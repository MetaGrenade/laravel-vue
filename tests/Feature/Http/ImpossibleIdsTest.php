<?php

namespace Tests\Feature\Http;

use App\Models\AuditLog;
use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route as Routes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionNamedType;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An address with something other than a record's number where the number goes is "not found" on every database
 * (app/Http/Middleware/RefuseImpossibleIds). SQLite, the database this suite usually runs on, finds nothing for such a value, so the
 * status alone cannot tell whether the value reached a query, which PostgreSQL answers with an error: these tests also look at what
 * the database was asked.
 */
class ImpossibleIdsTest extends TestCase
{
    use RefreshDatabase;

    /** Not a number, not a whole one, and one more than the biggest number a database column holds. */
    private const IMPOSSIBLE = ['abc', '1.5', '9223372036854775808'];

    /** @var list<mixed> What the queries since the last reset were given. */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function (QueryExecuted $query): void {
            array_push($this->asked, ...array_values($query->bindings));
        });
    }

    #[Test]
    public function every_address_that_takes_a_number_refuses_what_cannot_be_one_without_asking_the_database(): void
    {
        $admin = $this->admin();
        $this->withoutMiddleware(ThrottleRequests::class);

        $matched = null;
        Event::listen(function (RouteMatched $event) use (&$matched): void {
            $matched = $event->route->uri();
        });

        $checked = 0;
        $failures = [];

        foreach (Routes::getRoutes()->getRoutes() as $route) {
            foreach ($this->numberParameters($route) as $parameter) {
                foreach (self::IMPOSSIBLE as $value) {
                    // A route that allows digits only is not matched by anything else, so there is nothing of its own to try.
                    if (isset($route->wheres[$parameter]) && preg_match('#^(?:'.$route->wheres[$parameter].')$#', $value) !== 1) {
                        continue;
                    }

                    $method = head(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
                    $address = $this->addressOf($route, $parameter, $value);

                    // A request leaves the guard it signed in with as the default one, which the next request must not inherit.
                    $this->app['auth']->forgetGuards();
                    $this->app['auth']->shouldUse('web');
                    $this->actingAs($admin);
                    [$matched, $this->asked] = [null, []];

                    $status = $this->call($method, $address, server: ['HTTP_ACCEPT' => 'application/json'])->getStatusCode();
                    $what = "{$method} {$route->uri()} with {{$parameter}} = {$value}";

                    $this->assertSame($route->uri(), $matched, "{$what} was answered by another route");

                    if ($status !== 404) {
                        $failures[] = "{$what} answered {$status}";
                    }

                    if (in_array($value, $this->asked, true)) {
                        $failures[] = "{$what} asked the database for it";
                    }

                    $checked++;
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertGreaterThan(500, $checked, 'far fewer addresses were tried than the application has');
    }

    #[Test]
    public function a_number_a_record_could_have_is_looked_up_as_before(): void
    {
        $this->actingAs($this->admin());
        User::factory()->create(['id' => 7007]);

        $this->get('/acp/users/7007/edit')->assertOk();
        // A database reads leading zeros as the same number, and so an address with them still opens the record.
        $this->get('/acp/users/007007/edit')->assertOk();

        // The biggest number a column holds, and nought, are numbers like any other: they are asked for, and nobody has them.
        foreach (['9223372036854775807', '0'] as $number) {
            $this->asked = [];
            $this->get("/acp/users/{$number}/edit")->assertNotFound();
            $this->assertContains($number, $this->asked);
        }
    }

    /** The application's addresses write `{giftCard}`, but Laravel also takes `{gift_card}` for `$giftCard`. */
    #[Test]
    public function a_parameter_written_with_an_underscore_is_paired_as_laravel_pairs_it(): void
    {
        Routes::middleware('web')->get('/_ids/{chosen_user}', fn (User $chosenUser) => response()->noContent());

        $this->get('/_ids/'.User::factory()->create()->id)->assertNoContent();

        $this->asked = [];
        $this->get('/_ids/abc')->assertNotFound();
        $this->assertNotContains('abc', $this->asked);
    }

    #[Test]
    public function a_record_found_by_a_word_is_still_found_by_it(): void
    {
        $this->actingAs($this->admin());

        // The route says which column: `{board:slug}`.
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'position' => 1]);
        ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'News', 'slug' => 'news', 'position' => 1]);
        $this->get('/forum/news')->assertOk();

        // The model says which column: an order is found by its public id.
        $order = Order::factory()->create();
        $this->assertFalse(ctype_digit($order->getRouteKey()));
        $this->get(route('acp.commerce.orders.show', $order))->assertOk();

        // Nothing says it is a record at all: the controller takes the text and looks it up.
        Blog::factory()->published()->create(['slug' => 'hello-world']);
        $this->get('/blogs/hello-world')->assertOk();
    }

    /** What some databases read as the number before it (`7007abc` on MySQL, `7007.0` or `+7007` on SQLite) is not that number. */
    #[Test]
    public function a_number_with_something_added_to_it_opens_nothing(): void
    {
        $this->actingAs($this->admin());
        User::factory()->create(['id' => 7007]);

        foreach (['7007abc', '7007-ada', '7007.', '7007.0', '7.007e3', '+7007', '%207007', '7007%20'] as $address) {
            $this->asked = [];

            $this->get("/acp/users/{$address}/edit")->assertNotFound();

            $this->assertNotContains(rawurldecode($address), $this->asked, "{$address} was sent to the database");
        }
    }

    #[Test]
    public function somebody_not_signed_in_is_asked_to_sign_in_and_not_told_that_there_is_nothing_there(): void
    {
        $this->get('/acp/users/abc/edit')->assertRedirect(route('login'));
        $this->getJson('/api/v1/support/tickets/abc')->assertUnauthorized();
    }

    #[Test]
    public function the_audit_log_records_it_as_it_records_a_record_that_is_not_there(): void
    {
        $this->actingAs($this->admin());

        $this->put('/acp/users/abc/ban')->assertNotFound();

        $entry = AuditLog::query()->sole();
        $this->assertSame(AuditLog::FAILED, $entry->outcome);
        $this->assertSame(404, $entry->meta['status']);
        $this->assertNull($entry->subject_type);
    }

    #[Test]
    public function an_api_client_is_answered_and_counted_as_for_a_record_that_is_not_there(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $missing = $this->getJson('/api/v1/support/tickets/999999')->assertNotFound();
        $impossible = $this->getJson('/api/v1/support/tickets/abc')->assertNotFound();

        $this->assertSame(
            Str::replace('999999', 'abc', (string) $missing->json('message')),
            $impossible->json('message'),
        );
        $this->assertStringContainsString(class_basename(SupportTicket::class), (string) $impossible->json('message'));
        $this->assertSame(2, (int) UsageEvent::query()->where('user_id', $user->id)->where('meter', 'api-calls')->sum('quantity'));
    }

    #[Test]
    public function an_api_key_without_the_scope_is_refused_and_not_told_that_there_is_nothing_there(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['profile:read']);

        $this->getJson('/api/v1/support/tickets/abc')->assertForbidden();
    }

    /**
     * The parameters of a route that stand for a number, by what says so apart from the middleware that is tested: the route allows
     * digits only, the controller asks for an int, or the column the record is found by holds whole numbers.
     *
     * @return list<string>
     */
    private function numberParameters(Route $route): array
    {
        if ($route->parameterNames() === []) {
            return [];
        }

        $types = [];

        foreach ($route->signatureParameters() as $parameter) {
            $type = $parameter->getType();
            $types[$parameter->getName()] = $type instanceof ReflectionNamedType ? $type->getName() : '';
        }

        return array_values(array_filter($route->parameterNames(), function (string $name) use ($route, $types): bool {
            $type = $types[$name] ?? $types[Str::camel($name)] ?? '';

            if (($route->wheres[$name] ?? null) === '[0-9]+' || $type === 'int') {
                return true;
            }

            if (! is_subclass_of($type, Model::class)) {
                return false;
            }

            $model = new $type;

            return str_contains(Schema::getColumnType($model->getTable(), $route->bindingFieldFor($name) ?? $model->getRouteKeyName()), 'int');
        }));
    }

    /** The route's address with one parameter given as `$value` and each of the others as something the route accepts. */
    private function addressOf(Route $route, string $parameter, string $value): string
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $allowed = $route->wheres[$name] ?? null;

            $parameters[$name] = match (true) {
                $name === $parameter => $value,
                $allowed === null, $allowed === '[0-9]+' => '1',
                default => Str::before($allowed, '|'),
            };
        }

        if (in_array('signed', $route->gatherMiddleware(), true)) {
            return URL::signedRoute((string) $route->getName(), $parameters);
        }

        $address = preg_replace_callback('/\{(\w+)(?::\w+)?\??\}/', fn (array $match): string => $parameters[$match[1]], $route->uri());

        return '/'.ltrim((string) $address, '/');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));

        return $admin;
    }
}
