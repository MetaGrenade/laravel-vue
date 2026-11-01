<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSiteIsAvailable;
use App\Http\Middleware\SecurityHeaders;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Routing\PublicDocuments;
use App\Support\WebsiteSections;
use Illuminate\Auth\Events\Login;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The documents that are the same for everyone: no session, no cookie, and a cache may keep them (docs/http-caching.md).
 */
class PublicDocumentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, int}>
     */
    public static function documents(): array
    {
        return [
            'sitemap' => ['sitemap', 3600],
            'robots.txt' => ['robots', 3600],
            'security.txt' => ['security.txt', 3600],
            'web manifest' => ['webmanifest', 3600],
            'API schema' => ['api.docs.schema', 3600],
            'blog feed' => ['blogs.feed', 300],
            'forum feed' => ['forum.feed', 300],
            'board feed' => ['forum.boards.feed', 300],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function names(): array
    {
        return array_map(fn (array $document) => [$document[0]], self::documents());
    }

    #[DataProvider('names')]
    public function test_a_document_starts_no_session_and_sets_no_cookie(string $name): void
    {
        // The default for the suite is an array that nobody sees; the table is where a crawler's visit would be written.
        config(['session.driver' => 'database']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->get($this->address($name))->assertOk();
        $during = $queries;

        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame([], array_values(array_filter($during, fn (string $sql) => preg_match('/\bsessions\b/', $sql) === 1)));
    }

    public function test_a_page_still_starts_a_session_and_follows_the_reader(): void
    {
        config(['session.driver' => 'database', 'i18n.supported' => ['en', 'de']]);

        $response = $this->withCookie('appearance', 'dark')
            ->get(route('home'), ['Accept-Language' => 'de'])
            ->assertOk();

        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertNotSame([], $response->headers->getCookies());
        $this->assertSame('de', app()->getLocale());
    }

    #[DataProvider('documents')]
    public function test_a_cache_may_keep_a_document_and_a_reader_can_ask_whether_it_changed(string $name, int $seconds): void
    {
        // An empty feed says when it was updated: now.
        $this->freezeTime();

        $address = $this->address($name);
        $response = $this->get($address)->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age='.$seconds, $cacheControl);
        $this->assertNotNull($response->headers->get('ETag'));

        $unchanged = $this->get($address, ['If-None-Match' => $response->headers->get('ETag')]);

        $unchanged->assertStatus(304);
        $this->assertSame('', $unchanged->getContent());
    }

    #[DataProvider('names')]
    public function test_a_document_is_the_same_whoever_asks(string $name): void
    {
        config(['i18n.supported' => ['en', 'de']]);
        $this->freezeTime();

        $address = $this->address($name);
        $plain = $this->get($address)->assertOk();

        $reader = $this->withCookie('locale', 'de')
            ->withCookie('appearance', 'dark')
            ->get($address, ['Accept-Language' => 'de'])
            ->assertOk();

        $this->assertSame('en', app()->getLocale());
        $this->assertSame($plain->getContent(), $reader->getContent());
        $this->assertSame($plain->headers->get('ETag'), $reader->headers->get('ETag'));
    }

    public function test_an_answer_that_is_not_a_success_is_not_kept_by_a_cache(): void
    {
        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::BLOG] = false;
        SystemSetting::set('website_sections', $sections);

        $this->assertNotKept($this->get(route('blogs.feed'))->assertNotFound());

        SystemSetting::set('maintenance_mode', true);

        $this->assertNotKept($this->get(route('sitemap'))->assertStatus(503));

        // The contact stays up during maintenance, and is kept as it always is.
        $this->get(route('security.txt'))->assertOk()->assertHeader('ETag');
    }

    public function test_a_remember_me_cookie_signs_nobody_in_on_a_document_during_maintenance(): void
    {
        Event::fake([Login::class]);

        $admin = User::factory()->create();
        $admin->assignRole(Role::create(['name' => 'admin']));
        $admin->setRememberToken('a-token-from-an-earlier-sign-in');
        $admin->save();

        SystemSetting::set('maintenance_mode', true);

        $cookie = Auth::guard('web')->getRecallerName();
        $value = $admin->getKey().'|a-token-from-an-earlier-sign-in|'.$admin->getAuthPassword();

        // A page lets the member in, as it always did ...
        $this->withCookie($cookie, $value)->get(route('home'))->assertOk();
        Event::assertDispatched(Login::class);

        Event::fake([Login::class]);

        // ... and a document has no session to keep the sign-in in, so it does not start one.
        $this->withCookie($cookie, $value)->get(route('sitemap'))->assertStatus(503);
        Event::assertNotDispatched(Login::class);
    }

    public function test_a_new_web_middleware_has_to_be_decided_for_documents(): void
    {
        $web = app('router')->getMiddlewareGroups()['web'];

        $this->assertSame([], array_values(array_diff(PublicDocuments::WITHOUT, $web)), 'A middleware in PublicDocuments::WITHOUT is no longer in the web group.');

        $kept = array_values(array_diff($web, PublicDocuments::WITHOUT));
        $expected = [
            AddQueuedCookiesToResponse::class,
            EncryptCookies::class,
            EnsureSiteIsAvailable::class,
            SecurityHeaders::class,
            SubstituteBindings::class,
        ];
        sort($kept);
        sort($expected);

        $this->assertSame(
            $expected,
            $kept,
            'The web group gained or lost a middleware. A document runs it without a session: put it in PublicDocuments::WITHOUT if it needs one or changes the answer for the reader, or here if it is safe for a document.',
        );
    }

    public function test_only_the_documents_run_without_a_session(): void
    {
        $router = app('router');

        $names = collect($router->getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('web', $route->gatherMiddleware(), true))
            ->reject(fn ($route) => in_array(StartSession::class, $router->gatherRouteMiddleware($route), true))
            ->map(fn ($route) => $route->getName() ?? $route->uri())
            ->sort()
            ->values()
            ->all();

        $documents = collect(self::documents())->map(fn (array $document) => $document[0])->sort()->values()->all();

        $this->assertSame($documents, $names, 'A route runs without a session that is not a document in self::documents(), or the other way round. A new document needs a line there, and so a test of what it sends.');
    }

    private function address(string $name): string
    {
        if ($name !== 'forum.boards.feed') {
            return route($name);
        }

        $category = ForumCategory::query()->create(['slug' => 'general', 'title' => 'General', 'description' => 'Chat', 'position' => 1]);
        $board = ForumBoard::query()->create([
            'forum_category_id' => $category->id,
            'title' => 'Help',
            'slug' => 'help-board',
            'description' => null,
            'position' => 1,
        ]);

        return route($name, $board);
    }

    private function assertNotKept($response): void
    {
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('ETag'));
    }
}
