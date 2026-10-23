<?php

namespace Tests\Feature\Usage;

use App\Http\Middleware\MeterUsage;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Usage\Meters;
use App\Support\Usage\Usage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\GivesUsageAllowance;
use Tests\TestCase;

/**
 * Every request made with an API token is a use of the `api-calls` meter: counted, and held to what the account's plan allows.
 */
class MeterUsageTest extends TestCase
{
    use GivesUsageAllowance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    private function apiCall(User $user): TestResponse
    {
        Sanctum::actingAs($user, ['*']);

        return $this->getJson(route('api.v1.profile.show'));
    }

    private function uses(User $user): int
    {
        return (int) UsageEvent::query()->where('user_id', $user->id)->where('meter', 'api-calls')->sum('quantity');
    }

    // --- Counting -------------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_request_made_with_a_token_is_counted_against_the_account_and_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('example');

        $this->withToken($token->plainTextToken)->getJson(route('api.v1.profile.show'))->assertOk();

        $this->assertDatabaseHas('usage_events', [
            'user_id' => $user->id,
            'meter' => 'api-calls',
            'quantity' => 1,
            'token_id' => $token->accessToken->id,
        ]);
    }

    #[Test]
    public function every_request_is_one_use(): void
    {
        $user = User::factory()->create();

        $this->apiCall($user)->assertOk();
        $this->apiCall($user)->assertOk();
        $this->apiCall($user)->assertOk();

        $this->assertSame(3, $this->uses($user));
    }

    #[Test]
    public function a_request_with_nobody_signed_in_is_not_counted(): void
    {
        $this->getJson(route('api.v1.profile.show'))->assertUnauthorized();
        $this->getJson(route('api.v1.blogs.index'))->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function the_meter_on_a_route_nobody_has_to_sign_in_to_use_lets_a_visitor_through_uncounted(): void
    {
        Route::middleware('usage:api-calls')->get('/__usage/open', fn () => 'open');

        $this->get('/__usage/open')->assertOk()->assertSee('open');

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_failure_on_the_servers_side_is_not_the_callers_use_but_one_on_theirs_is(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        Route::middleware(['auth:sanctum', 'usage:api-calls'])->group(function () {
            Route::get('/__usage/broken', fn () => response('broken', 503));
            Route::get('/__usage/explodes', fn () => throw new RuntimeException('boom'));
            Route::get('/__usage/missing', fn () => response('missing', 404));
            Route::get('/__usage/invalid', fn () => response('invalid', 422));
        });

        $this->get('/__usage/broken')->assertStatus(503);
        $this->get('/__usage/explodes')->assertStatus(500);
        $this->assertSame(0, $this->uses($user));

        $this->get('/__usage/missing')->assertNotFound();
        $this->get('/__usage/invalid')->assertStatus(422);
        $this->assertSame(2, $this->uses($user));
    }

    /**
     * Authenticated API routes whose address names something (a poll, a blog and its comment, a ticket) that Laravel looks up before
     * the request reaches its controller, with a name that is not there.
     *
     * @return array<string, array{string, string, array<string, string|int>}>
     */
    public static function routesWithAMissingResource(): array
    {
        return [
            'a poll' => ['post', 'api.v1.polls.vote', ['poll' => 'no-such-poll']],
            'a blog comment' => ['patch', 'api.v1.blogs.comments.update', ['blog' => 'no-such-blog', 'comment' => 1]],
            'a support ticket' => ['get', 'api.v1.support.tickets.show', ['ticket' => 999999]],
        ];
    }

    /**
     * @param  array<string, string|int>  $parameters
     */
    #[Test]
    #[DataProvider('routesWithAMissingResource')]
    public function a_request_for_something_that_is_not_there_is_still_a_use(string $method, string $route, array $parameters): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->{$method.'Json'}(route($route, $parameters))->assertNotFound();

        // Laravel looks the thing up before the controller runs, and a 404 is the caller's mistake, which is a use.
        $this->assertSame(1, $this->uses($user));
    }

    /**
     * @param  array<string, string|int>  $parameters
     */
    #[Test]
    #[DataProvider('routesWithAMissingResource')]
    public function an_account_at_its_limit_is_refused_before_what_it_asks_for_is_looked_up(string $method, string $route, array $parameters): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 1);
        Sanctum::actingAs($user, ['*']);

        $this->{$method.'Json'}(route($route, $parameters))->assertNotFound();

        // Used up: a 429, not the 404 that looking the thing up would have given.
        $this->{$method.'Json'}(route($route, $parameters))->assertStatus(429);
        $this->assertSame(1, $this->uses($user));
    }

    #[Test]
    public function somebody_not_signed_in_is_told_so_and_not_that_what_they_asked_for_is_missing(): void
    {
        // Authentication stays ahead of the meter (which needs to know whose use it is), and so ahead of looking anything up.
        $this->postJson(route('api.v1.polls.vote', ['poll' => 'no-such-poll']))->assertUnauthorized();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function the_meter_runs_after_authentication_and_the_rate_limits_and_before_the_lookup_of_what_a_route_names(): void
    {
        $router = app('router');
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('usage:api-calls', $route->gatherMiddleware(), true)) {
                continue;
            }

            // The order the middleware really runs in: Laravel sorts it by its priority list, not by how the group is written.
            $order = array_map(fn ($middleware) => is_string($middleware) ? head(explode(':', $middleware)) : '', $router->gatherRouteMiddleware($route));
            $at = fn (string $class) => array_search($class, $order, true);

            $this->assertNotFalse($at(MeterUsage::class), $route->uri());
            $this->assertLessThan($at(MeterUsage::class), $at(Authenticate::class), $route->uri().': signed in before it is counted');
            $this->assertLessThan($at(SubstituteBindings::class), $at(MeterUsage::class), $route->uri().': counted before what it names is looked up');

            foreach ($order as $position => $class) {
                if ($class === ThrottleRequests::class) {
                    $this->assertLessThan($at(MeterUsage::class), $position, $route->uri().': a rate limit stops a request before it is counted');
                }
            }

            $checked++;
        }

        $this->assertGreaterThan(10, $checked);
    }

    #[Test]
    public function a_ledger_that_cannot_be_written_does_not_turn_a_good_request_into_a_failure(): void
    {
        Exceptions::fake();
        $this->app->bind(Usage::class, fn () => new class(app(Meters::class)) extends Usage
        {
            public function record(User $user, string $meter, int $quantity = 1, ?string $key = null, ?CarbonInterface $at = null, ?int $tokenId = null): bool
            {
                throw new RuntimeException('the ledger is down');
            }
        });

        $user = User::factory()->create();
        $this->onAPlanGiving($user, 5);

        $response = $this->apiCall($user)->assertOk();

        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'the ledger is down');
        // Not counted, so it is not claimed to have been: the headers say what was true before.
        $this->assertSame('5', $response->headers->get('X-Usage-Remaining'));
    }

    #[Test]
    public function a_meter_that_does_not_exist_is_a_mistake_and_not_a_free_pass(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        Route::middleware(['auth:sanctum', 'usage:no-such-meter'])->get('/__usage/typo', fn () => 'ok');

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);

        $this->get('/__usage/typo');
    }

    // --- Holding an account to what it may use --------------------------------------------------------------------------------------------

    #[Test]
    public function an_account_with_an_allowance_is_told_where_it_stands_on_every_response(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 3);

        foreach ([2, 1, 0] as $remaining) {
            $this->apiCall($user)->assertOk()
                ->assertHeader('X-Usage-Limit', '3')
                ->assertHeader('X-Usage-Remaining', (string) $remaining)
                ->assertHeader('X-Usage-Resets-At', '2026-11-01T00:00:00+00:00');
        }
    }

    #[Test]
    public function an_account_with_no_allowance_is_counted_but_not_limited_and_not_told_one(): void
    {
        $user = User::factory()->create();

        for ($call = 1; $call <= 5; $call++) {
            $response = $this->apiCall($user)->assertOk();
        }

        $this->assertSame(5, $this->uses($user));
        $this->assertNull($response->headers->get('X-Usage-Limit'));
        $this->assertNull($response->headers->get('X-Usage-Remaining'));
    }

    #[Test]
    public function an_account_that_has_used_all_it_may_is_refused_and_the_refusal_is_not_a_use(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 3);

        for ($call = 1; $call <= 3; $call++) {
            $this->apiCall($user)->assertOk();
        }

        $this->apiCall($user)
            ->assertStatus(429)
            ->assertJson([
                'message' => 'You have reached this month\'s limit of 3 calls. It resets on 2026-11-01.',
                'meter' => 'api-calls',
                'limit' => 3,
                'used' => 3,
                'resets_at' => '2026-11-01T00:00:00+00:00',
            ])
            ->assertHeader('X-Usage-Limit', '3')
            ->assertHeader('X-Usage-Remaining', '0')
            // Until the first of next month: 16 days and 12 hours from the middle of October.
            ->assertHeader('Retry-After', (string) ((16 * 24 + 12) * 3600));

        $this->assertSame(3, $this->uses($user));
    }

    #[Test]
    public function a_refused_request_never_reaches_what_it_asked_for(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 1);
        Sanctum::actingAs($user, ['*']);
        $reached = 0;
        Route::middleware(['auth:sanctum', 'usage:api-calls'])->get('/__usage/work', function () use (&$reached) {
            $reached++;

            return 'done';
        });

        $this->get('/__usage/work')->assertOk();
        $this->get('/__usage/work')->assertStatus(429);

        $this->assertSame(1, $reached);
    }

    #[Test]
    public function the_allowance_comes_back_with_the_new_month(): void
    {
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 1);

        $this->apiCall($user)->assertOk();
        $this->apiCall($user)->assertStatus(429);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:00:00'));

        $this->apiCall($user)->assertOk()->assertHeader('X-Usage-Remaining', '0');
    }

    #[Test]
    public function a_meter_that_allows_overage_carries_on_counting_past_the_allowance(): void
    {
        config(['billing.usage.meters.api-calls.overage' => 'allow']);
        $user = User::factory()->create();
        $this->onAPlanGiving($user, 2);

        for ($call = 1; $call <= 4; $call++) {
            $this->apiCall($user)->assertOk()->assertHeader('X-Usage-Limit', '2');
        }

        $this->assertSame(4, $this->uses($user));
    }

    #[Test]
    public function an_account_with_no_plan_is_held_to_the_free_allowance_when_there_is_one(): void
    {
        config(['billing.usage.meters.api-calls.free_allowance' => 2]);
        $user = User::factory()->create();

        $this->apiCall($user)->assertOk();
        $this->apiCall($user)->assertOk();
        $this->apiCall($user)->assertStatus(429);
    }

    #[Test]
    public function one_accounts_uses_are_not_another_accounts(): void
    {
        $busy = User::factory()->create();
        $quiet = User::factory()->create();
        $this->onAPlanGiving($busy, 1);
        $this->onAPlanGiving($quiet, 1);

        $this->apiCall($busy)->assertOk();
        $this->apiCall($busy)->assertStatus(429);

        $this->apiCall($quiet)->assertOk();
    }

    // --- Where it applies -----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function every_route_that_needs_an_api_token_is_metered(): void
    {
        $unmetered = [];
        $metered = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! str_starts_with($route->uri(), 'api/') || ! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            in_array('usage:api-calls', $middleware, true) ? $metered++ : $unmetered[] = implode(',', $route->methods()).' '.$route->uri();
        }

        $this->assertSame([], $unmetered, 'A route that needs a token was left out of the meter.');
        $this->assertGreaterThan(10, $metered);
    }

    #[Test]
    public function a_request_a_rate_limit_stops_is_not_a_use(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // A route's own rate limit is sorted ahead of the meter, so the meter never sees what it stops.
        Route::middleware(['auth:sanctum', 'usage:api-calls'])->get('/__usage/limited', fn () => 'ok')->middleware('throttle:1,1');

        $this->get('/__usage/limited')->assertOk();
        $this->get('/__usage/limited')->assertStatus(429);
        $this->get('/__usage/limited')->assertStatus(429);

        $this->assertSame(1, $this->uses($user));
    }

    #[Test]
    public function a_request_a_tokens_own_quota_stops_is_not_a_use_either(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('quota');
        $token->accessToken->forceFill(['hourly_quota' => 1])->save();

        // The token's quota is checked after the meter has let the request through, so the meter sees its 429 and must not count it.
        $this->withToken($token->plainTextToken)->getJson(route('api.v1.profile.show'))->assertOk();
        $this->withToken($token->plainTextToken)->getJson(route('api.v1.profile.show'))->assertStatus(429)->assertJsonPath('message', 'API quota exceeded for this token.');
        $this->withToken($token->plainTextToken)->getJson(route('api.v1.profile.show'))->assertStatus(429);

        $this->assertSame(1, $this->uses($user));
    }
}
