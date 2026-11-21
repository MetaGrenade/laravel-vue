<?php

namespace Tests\Feature\Http;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A page that takes text from the query string must not answer 500 when the query string sends a list instead (`?search[]=a`, which PHP
 * hands over as an array). `(string)` on an array is a warning, Laravel raises warnings as exceptions, and `$request->string()` casts the
 * same way, so a request nobody meant ended in an error page and a line in the log. Scanners send `[]` on every name they find.
 * `App\Support\Http\RequestText` reads text safely; a list counts as the parameter not having been sent.
 *
 * Three ways to hold that: every page without a route parameter is asked for with every name the code reads, as a guest and as an
 * administrator; the pages that need a row to exist, and the sign-in endpoints, are asked for one by one; and the old way of reading is
 * not allowed back (the last test), which is what covers the pages nothing here asks for.
 */
class QueryStringShapesTest extends TestCase
{
    use RefreshDatabase;

    /** Pages that are not for a browser, or call a payment provider. */
    private const SKIPPED = ['up', '_inertia/devtools/entries', 'broadcasting/auth', 'sanctum/csrf-cookie', 'logout'];

    /** @return list<string> every name the code reads from a request, found in what it says */
    private function names(): array
    {
        $names = ['page'];

        foreach ($this->sourceFiles(['Http', 'Support']) as $source) {
            preg_match_all('/->(?:query|input|string|integer|boolean|get|has|filled|date|str|float|array|collect)\(\s*\'([a-z_][a-z0-9_.-]*)\'/i', $source, $methods);
            preg_match_all('/RequestText::(?:query|input)\(\$[a-z]+,\s*\'([a-z_][a-z0-9_.-]*)\'/i', $source, $helper);
            array_push($names, ...$methods[1], ...$helper[1]);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $folders  inside app/
     * @return array<string, string> the source of each file, by path
     */
    private function sourceFiles(array $folders): array
    {
        $sources = [];

        foreach ($folders as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($folder))) as $file) {
                if ($file->getExtension() === 'php') {
                    $sources[str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1))] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $sources;
    }

    /** @return list<string> the pages that take no route parameter */
    private function pages(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route) => in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{'))
            ->map(fn (LaravelRoute $route) => $route->uri())
            ->reject(fn (string $uri) => in_array($uri, self::SKIPPED, true) || str_starts_with($uri, 'settings/billing'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $names
     * @param  string|list<string>  $value  what every name is set to: a list by default
     */
    private function address(string $uri, array $names, string|array $value = ['x', 'y']): string
    {
        return '/'.ltrim($uri, '/').'?'.http_build_query(array_fill_keys($names, $value));
    }

    /**
     * One request. The default guard is switched to `sanctum` by an API page and stays so for the rest of the test process (a real
     * request starts again from the configuration), so it is put back each time. 500 is also what a page gets that answers 200 with
     * a body nobody can read: Inertia puts the page in the HTML as JSON, and when the page holds something that cannot be encoded
     * (bytes that are not UTF-8) the script tag comes out empty, which is a blank screen.
     */
    private function visit(string $address): int
    {
        app(AuthFactory::class)->shouldUse('web');

        $response = $this->get($address);

        return $response->getStatusCode() < 500 && $this->unreadable($response) ? 599 : $response->getStatusCode();
    }

    private function unreadable(TestResponse $response): bool
    {
        $body = (string) $response->getContent();

        if (str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return json_decode($body) === null && trim($body) !== 'null';
        }

        return preg_match('#<script data-page="[^"]*" type="application/json">(.*?)</script>#s', $body, $page) === 1 && json_decode($page[1]) === null;
    }

    /**
     * Which of the names make the page fail, found by halving the list (a page that fails on the whole list may fail on one name).
     *
     * @param  list<string>  $names
     * @param  string|list<string>  $value
     * @return list<string>
     */
    private function culprits(string $uri, array $names, string|array $value = ['x', 'y']): array
    {
        if ($this->visit($this->address($uri, $names, $value)) < 500) {
            return [];
        }

        if (count($names) === 1) {
            return $names;
        }

        $halves = array_chunk($names, (int) ceil(count($names) / 2));
        $found = array_merge($this->culprits($uri, $halves[0], $value), $this->culprits($uri, $halves[1], $value));

        return $found === [] ? ['(only together) '.implode(', ', $names)] : $found;
    }

    /**
     * Every page without a route parameter, asked with every name set to $value, as a guest and as an administrator.
     *
     * @param  string|list<string>  $value
     * @param  list<string>|null  $only  the names to set, all of them when null
     * @return list<string> the pages that failed, and because of which names
     */
    private function walk(string|array $value, ?array $only = null): array
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $names = $only ?? $this->names();
        $failures = [];

        foreach ([false, true] as $signedIn) {
            $this->app['auth']->forgetGuards();

            if ($signedIn) {
                $this->actingAs($admin);
            }

            foreach ($this->pages() as $uri) {
                if ($this->visit($this->address($uri, $names, $value)) >= 500) {
                    $failures[] = ($signedIn ? 'as an administrator: ' : 'as a guest: ').$uri.' (because of '.implode(', ', $this->culprits($uri, $names, $value)).')';
                }
            }
        }

        return $failures;
    }

    #[Test]
    public function a_list_where_a_text_is_expected_is_never_a_server_error_on_a_page_without_a_route_parameter(): void
    {
        $failures = $this->walk(['x', 'y']);

        $this->assertSame([], $failures, 'These pages answered 500 when a query string name was a list. Read it with App\Support\Http\RequestText ('.count($this->names())." names tried):\n".implode("\n", $failures));
    }

    #[Test]
    public function text_that_is_not_utf8_is_never_a_server_error_or_a_blank_page_on_a_page_without_a_route_parameter(): void
    {
        // `?search=%FF`: nothing a browser sends, but a link typed by hand, an old page in another encoding or a scanner does. The live
        // search answered 500, and the lists of blog posts, members and support and a dozen admin lists came back with their page
        // data empty. App\Http\Middleware\ScrubMalformedInput replaces such bytes before anything reads them.
        $failures = $this->walk("\xFF\xFE caf\xE9");

        $this->assertSame([], $failures, "These pages answered 500, or with page data that cannot be read, when a query string name held bytes that are not UTF-8:\n".implode("\n", $failures));
    }

    #[Test]
    public function a_page_size_that_is_not_a_sensible_number_is_never_a_server_error(): void
    {
        // `paginate(-1)` drops the LIMIT and keeps `OFFSET 0`, which no database accepts: a list with a row to show answered 500. A list
        // with nothing to show never asks the database, so the lists get a row each.
        $admin = $this->administratorWithRowsInEveryList();

        $names = array_values(array_filter($this->names(), fn (string $name) => preg_match('/per_page$|^limit$/', $name) === 1));
        $this->assertContains('per_page', $names);
        $failures = [];

        foreach (['-1', '0', '-99999', '1000', '99999999999999999999', 'lots'] as $value) {
            foreach ([false, true] as $signedIn) {
                $this->app['auth']->forgetGuards();

                if ($signedIn) {
                    $this->actingAs($admin);
                }

                foreach ($this->pages() as $uri) {
                    if ($this->visit($this->address($uri, $names, $value)) >= 500) {
                        $failures[] = "per_page=$value, ".($signedIn ? 'as an administrator' : 'as a guest').": $uri (because of ".implode(', ', $this->culprits($uri, $names, $value)).')';
                    }
                }
            }
        }

        $this->assertSame([], $failures, 'These pages answered 500 when a page size was not a number from 1 up (names tried: '.implode(', ', $names)."):\n".implode("\n", $failures));
    }

    /** An administrator, and a row in each list that has a size: a list with nothing to show never asks the database for a page. */
    private function administratorWithRowsInEveryList(): User
    {
        $admin = User::factory()->create();
        Role::findOrCreate('admin');
        $admin->assignRole('admin');
        Permission::findOrCreate('probe.permission');
        Blog::factory()->published()->create();
        SupportTicket::factory()->create();
        Faq::factory()->create();
        $admin->createToken('probe');

        return $admin;
    }

    /** @return array<string, array{string, string, int}> the list, where its page size is, and the size it has when none is asked for */
    public static function adminLists(): array
    {
        return [
            'users' => ['/acp/users', 'users.meta.per_page', 15],
            'roles' => ['/acp/acl', 'roles.meta.per_page', 15],
            'permissions' => ['/acp/acl', 'permissions.meta.per_page', 15],
            'blog posts' => ['/acp/blogs', 'blogs.meta.per_page', 15],
            'support tickets' => ['/acp/support', 'tickets.meta.per_page', 25],
            'FAQs' => ['/acp/support', 'faqs.meta.per_page', 25],
            'API keys' => ['/acp/tokens', 'tokens.meta.per_page', 10],
        ];
    }

    #[Test]
    #[DataProvider('adminLists')]
    public function the_page_size_of_an_admin_list_is_kept_between_1_and_100(string $uri, string $path, int $default): void
    {
        $this->actingAs($this->administratorWithRowsInEveryList());

        $expected = [
            '' => $default,
            '?per_page=-5' => 1,
            '?per_page=0' => 1,
            '?per_page=1000' => 100,
            '?per_page=7' => 7,
            '?per_page=lots' => $default,
            '?per_page=99999999999999999999' => $default,
        ];

        foreach ($expected as $query => $size) {
            $this->get($uri.$query)->assertOk()->assertInertia(fn (Assert $page) => $page->where($path, $size));
        }
    }

    #[Test]
    public function a_list_where_a_text_is_expected_is_never_a_server_error_on_a_page_that_needs_a_row(): void
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'The lounge', 'slug' => 'the-lounge', 'description' => 'Chat', 'position' => 1]);
        $blog = Blog::factory()->published()->create();
        $product = Product::create(['name' => 'Hoodie', 'slug' => 'hoodie', 'is_active' => true]);

        config(['services.google' => ['client_id' => 'client', 'client_secret' => 'secret', 'redirect' => 'https://example.test/callback']]);
        Http::fake();

        $pages = [
            'a forum board' => [route('forum.boards.show', $board, false), ['search', 'prefix', 'solved', 'page', 'sort']],
            'the comments of a blog post' => [route('blogs.comments.index', $blog, false), ['sort', 'page']],
            'the comments of a blog post in the API' => [route('api.v1.blogs.comments.index', $blog, false), ['sort', 'page', 'per_page']],
            'a product' => [route('shop.products.show', $product, false), ['review_sort', 'page']],
            'the return from a sign-in provider' => [route('oauth.callback', ['provider' => 'google'], false), ['code', 'state', 'error']],
        ];

        $failures = [];

        foreach ($pages as $name => [$uri, $queryNames]) {
            foreach ($queryNames as $queryName) {
                $status = $this->withSession(['oauth:state:google' => 'the-state'])->get($uri.'?'.http_build_query([$queryName => ['x', 'y']]))->getStatusCode();

                if ($status >= 500) {
                    $failures[] = "$name, with $queryName as a list: $status";
                }
            }
        }

        $this->assertSame([], $failures, "These answered 500 when a query string name was a list:\n".implode("\n", $failures));
    }

    /**
     * These did not fail on the old reads (a rule or an earlier check refuses a list first), so this is a guard and not a reproduction;
     * what keeps the old way of reading out of them is the last test.
     */
    #[Test]
    public function a_list_sent_to_the_sign_in_endpoints_is_refused_and_not_a_server_error(): void
    {
        $user = User::factory()->create();
        $secret = TwoFactorAuthenticator::generateSecret();
        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(['TEST-ONE-USED']),
        ])->save();

        $this->assertLessThan(500, $this->post('/login', ['email' => ['x'], 'password' => ['y']])->getStatusCode(), 'The sign-in form');
        $this->assertLessThan(500, $this->postJson('/api/v1/auth/token', ['email' => ['x'], 'password' => 'y', 'device_name' => 'phone'])->getStatusCode(), 'The sign-in of the API');
        $this->assertLessThan(500, $this->withSession(['two_factor:id' => $user->id])->post(route('two-factor.login'), ['code' => ['x'], 'recovery_code' => ['y']])->getStatusCode(), 'The two-factor challenge');

        $this->actingAs($user);
        $this->assertLessThan(500, $this->post(route('security.mfa.confirm', absolute: false), ['code' => ['x']])->getStatusCode(), 'Confirming two-factor in the settings');
    }

    #[Test]
    public function the_old_way_of_reading_text_from_a_request_is_not_used(): void
    {
        $found = [];

        foreach ($this->sourceFiles(['Http', 'Support']) as $path => $source) {
            if ($path === 'Support/Http/RequestText.php') {
                continue; // says what is wrong with the old way
            }

            foreach (preg_split('/\R/', $source) ?: [] as $number => $line) {
                if (preg_match('/\(string\)\s*\$(?:request|this)->(?:query|input)\(|\$(?:request|this)->string\(/', $line) === 1) {
                    $found[] = $path.':'.($number + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $found, "These read text from a request in a way that fails on a list (`?x[]=a`, an array to a string is an exception). Use App\\Support\\Http\\RequestText::query() or ::input():\n".implode("\n", $found));
    }
}
