<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\EnsureTokenScope;
use App\Http\Middleware\MeterUsage;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Api\Scopes;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A key can be limited to some of what the API does, and every route that takes a key says which scope it needs (docs/api-keys.md).
 */
class TokenScopesTest extends TestCase
{
    use RefreshDatabase;

    /** Routes that take a key and need no scope: a key can always remove itself. */
    private const UNSCOPED = ['api.v1.auth.token.destroy'];

    /**
     * @return list<RoutingRoute>
     */
    private static function keyRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/') && in_array('auth:sanctum', $route->gatherMiddleware(), true),
        ));
    }

    /**
     * The scope each route asks for.
     *
     * @return list<string>
     */
    private static function scopesOf(RoutingRoute $route): array
    {
        $scopes = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'scope:')) {
                array_push($scopes, ...explode(',', substr($middleware, 6)));
            }
        }

        return $scopes;
    }

    /**
     * Each route that asks for a scope, as the method and address to call it with (what it names is a number that is not there).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function scopedRoutes(): array
    {
        $cases = [];

        foreach (self::keyRoutes() as $route) {
            $scopes = self::scopesOf($route);

            if ($scopes !== []) {
                $method = collect($route->methods())->first(fn (string $method) => $method !== 'HEAD');
                $cases[] = [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri()), $scopes[0]];
            }
        }

        return $cases;
    }

    /**
     * Call a route with a new key that holds the given scopes (a request at a time: the guard keeps the user it found).
     *
     * @param  list<string>  $abilities
     */
    private function callWithKey(array $abilities, string $method, string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $key = User::factory()->create()->createToken('example', $abilities);

        return $this->withToken($key->plainTextToken)->json($method, $uri);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function key(array $abilities): string
    {
        return User::factory()->create()->createToken('example', $abilities)->plainTextToken;
    }

    // --- The routes -----------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function every_route_that_takes_a_key_asks_for_a_scope(): void
    {
        $unscoped = [];

        foreach (self::keyRoutes() as $route) {
            if (self::scopesOf($route) === [] && ! in_array($route->getName(), self::UNSCOPED, true)) {
                $unscoped[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $unscoped, 'a route that takes a key must say which scope it needs (scope:name), or be listed as needing none');
        $this->assertGreaterThan(15, count(self::keyRoutes()));
    }

    #[Test]
    public function every_scope_a_route_asks_for_exists_and_every_scope_is_asked_for(): void
    {
        $asked = [];

        foreach (self::keyRoutes() as $route) {
            array_push($asked, ...self::scopesOf($route));
        }

        $scopes = app(Scopes::class)->keys();

        $this->assertSame([], array_values(array_diff($asked, $scopes)), 'a route asks for a scope the site does not have');
        $this->assertSame([], array_values(array_diff($scopes, $asked)), 'a scope no route asks for gives a key nothing');
    }

    #[Test]
    public function the_scope_is_checked_after_authentication_and_the_meter_and_before_the_lookup_of_what_a_route_names(): void
    {
        $router = app('router');
        $checked = 0;

        foreach (self::keyRoutes() as $route) {
            if (self::scopesOf($route) === []) {
                continue;
            }

            $order = array_map(fn ($middleware) => is_string($middleware) ? head(explode(':', $middleware)) : '', $router->gatherRouteMiddleware($route));
            $at = fn (string $class) => array_search($class, $order, true);

            $this->assertNotFalse($at(EnsureTokenScope::class), $route->uri());
            $this->assertLessThan($at(EnsureTokenScope::class), $at(Authenticate::class), $route->uri().': signed in before the scope is checked');
            $this->assertLessThan($at(EnsureTokenScope::class), $at(MeterUsage::class), $route->uri().': counted before the scope is checked');
            $this->assertLessThan($at(SubstituteBindings::class), $at(EnsureTokenScope::class), $route->uri().': checked before what it names is looked up');

            $checked++;
        }

        $this->assertGreaterThan(15, $checked);
    }

    #[Test]
    public function a_key_without_the_scope_is_refused_whether_or_not_what_it_asked_for_exists(): void
    {
        $all = app(Scopes::class)->keys();

        foreach (self::scopedRoutes() as [$method, $uri, $scope]) {
            $response = $this->callWithKey(array_values(array_diff($all, [$scope])), $method, $uri);

            $this->assertSame(403, $response->status(), $method.' '.$uri.' let in a key without '.$scope);
            $this->assertSame($scope, $response->json('scope'), $method.' '.$uri);
        }
    }

    #[Test]
    public function a_key_with_only_the_scope_is_not_refused_for_want_of_one(): void
    {
        foreach (self::scopedRoutes() as [$method, $uri, $scope]) {
            $response = $this->callWithKey([$scope], $method, $uri);

            $this->assertNull($response->json('scope'), $method.' '.$uri.' refused a key that has '.$scope);
        }
    }

    #[Test]
    public function a_key_with_every_scope_is_not_refused_for_want_of_one(): void
    {
        foreach (self::scopedRoutes() as [$method, $uri]) {
            $this->assertNull($this->callWithKey(['*'], $method, $uri)->json('scope'), $method.' '.$uri);
        }
    }

    // --- Behaviour ------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_key_with_the_scope_reads_what_it_is_for(): void
    {
        $token = $this->key(['profile:read']);

        $this->withToken($token)->getJson('/api/user')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
    }

    #[Test]
    public function a_key_for_something_else_is_told_which_scope_it_lacks(): void
    {
        $token = $this->key(['blog:write']);

        $this->withToken($token)->getJson('/api/user')
            ->assertForbidden()
            ->assertExactJson(['message' => 'This API key does not have the "profile:read" scope.', 'scope' => 'profile:read']);
    }

    #[Test]
    public function a_key_can_always_remove_itself(): void
    {
        $token = $this->key(['blog:write']);

        $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();
    }

    #[Test]
    public function a_key_with_no_scopes_does_nothing(): void
    {
        $token = $this->key([]);

        $this->withToken($token)->getJson('/api/user')->assertForbidden();
    }

    #[Test]
    public function a_key_with_abilities_the_site_does_not_know_is_held_to_them(): void
    {
        // What a key was given before scopes were enforced: free text that no route ever looked at.
        $token = $this->key(['read', 'update']);

        $this->withToken($token)->getJson('/api/user')->assertForbidden();
    }

    #[Test]
    public function a_person_signed_in_with_a_session_is_not_limited(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/user')->assertOk();
    }

    #[Test]
    public function a_visitor_is_still_asked_to_sign_in_rather_than_told_about_scopes(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    #[Test]
    public function holding_any_one_of_several_scopes_is_enough(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'scope:blog:write,polls:vote'])->get('/api/_either', fn () => response()->json(['ok' => true]));

        $polls = $this->key(['polls:vote']);
        $this->withToken($polls)->getJson('/api/_either')->assertOk();

        $this->app['auth']->forgetGuards();
        $blog = $this->key(['blog:write']);
        $this->withToken($blog)->getJson('/api/_either')->assertOk();

        $this->app['auth']->forgetGuards();
        $neither = $this->key(['profile:read']);
        $this->withToken($neither)->getJson('/api/_either')->assertForbidden()->assertJson(['scope' => 'blog:write']);
    }

    #[Test]
    public function a_route_that_asks_for_a_scope_the_site_does_not_have_fails_loudly(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'scope:no-such-scope'])->get('/api/_typo', fn () => response()->json(['ok' => true]));

        $token = $this->key(['*']);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no-such-scope');

        $this->withToken($token)->getJson('/api/_typo');
    }

    #[Test]
    public function the_middleware_asks_a_request_with_nobody_behind_it_to_sign_in(): void
    {
        $this->expectException(AuthenticationException::class);

        app(EnsureTokenScope::class)->handle(Request::create('/api/user'), fn () => response('ok'), 'profile:read');
    }

    #[Test]
    public function the_middleware_refuses_somebody_who_has_no_token_at_all(): void
    {
        $request = Request::create('/api/user');
        $request->setUserResolver(fn () => User::factory()->create());

        $response = app(EnsureTokenScope::class)->handle($request, fn () => response('ok'), 'profile:read');

        $this->assertSame(403, $response->getStatusCode(), 'holding no token, they hold no scope');
    }

    #[Test]
    public function a_refusal_for_want_of_a_scope_still_counts_as_a_call(): void
    {
        $token = $this->key(['blog:write']);

        $this->withToken($token)->getJson('/api/user')->assertForbidden();

        $this->assertSame(1, UsageEvent::query()->where('meter', 'api-calls')->count(), 'like any other refusal, it was a request the server had to answer');
    }

    #[Test]
    public function a_moderator_key_needs_the_scope_as_well_as_the_role(): void
    {
        Role::findOrCreate('moderator');
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $withRoleOnly = $moderator->createToken('no scope', ['forum:write']);
        $this->withToken($withRoleOnly->plainTextToken)->patchJson('/api/v1/forum/boards/1/threads/1/lock')
            ->assertForbidden()->assertJson(['scope' => 'forum:moderate']);

        $this->app['auth']->forgetGuards();
        $withScope = $moderator->createToken('moderating', ['forum:moderate']);
        $response = $this->withToken($withScope->plainTextToken)->patchJson('/api/v1/forum/boards/1/threads/1/lock');

        $this->assertNull($response->json('scope'), 'passed the scope check (and then found no such thread)');
    }
}
