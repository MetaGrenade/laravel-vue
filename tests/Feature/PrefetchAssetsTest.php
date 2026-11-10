<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Frontend\StaffAreaVite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Only a signed-in user is asked to fetch the rest of the front end in the background once a page has drawn (about 980 kB gzipped):
 * a visitor often reads one page, and the fetch also puts a 71 kB script in every page they are sent. The staff area's pages are 30 %
 * of it and go to staff only (app/Http/Middleware/PrefetchAssetsForMembers.php, docs/bundle-budget.md).
 */
class PrefetchAssetsTest extends TestCase
{
    use RefreshDatabase;

    /** A line of the script Laravel writes into a page for Vite::prefetch, which no other part of a page has. */
    private const PREFETCH_SCRIPT = 'const loadNext';

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about the built front end (they read public/build/manifest.json). While a Vite dev server runs, it keeps
        // public/hot, and Laravel then draws the dev server's addresses instead of the built files, so a page has no prefetch at all.
        // That file is the dev server's, so it is neither moved nor deleted: Vite is told to look for its hot file somewhere nothing
        // ever writes one.
        Vite::useHotFile(storage_path('framework/testing/vite-no-hot-file'));
    }

    #[Test]
    public function a_visitor_is_not_asked_to_fetch_the_rest_of_the_front_end(): void
    {
        $this->get(route('home'))->assertOk()->assertDontSee(self::PREFETCH_SCRIPT, false);
        $this->get(route('login'))->assertOk()->assertDontSee(self::PREFETCH_SCRIPT, false);
    }

    #[Test]
    public function a_signed_in_user_is_asked_to_fetch_it_three_files_at_a_time(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();

        $response->assertSee(self::PREFETCH_SCRIPT, false);
        // The call that starts it, `loadNext(<the files>, 3)`. (Not assertMatchesRegularExpression: it would print the whole page.)
        $this->assertSame(1, preg_match('/loadNext\([^\n]*, 3\)/', (string) $response->getContent()), 'The files are not fetched three at a time.');
    }

    #[Test]
    public function a_visitor_served_after_a_member_by_the_same_worker_is_not_asked_either(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertSee(self::PREFETCH_SCRIPT, false);

        // Vite is one object for as long as the worker lives (Octane, docs/octane.md), so what the last request set is still there
        // unless this one sets it again. The next request has nobody signed in.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get(route('home'))->assertOk()->assertDontSee(self::PREFETCH_SCRIPT, false);
    }

    #[Test]
    public function a_member_served_after_a_visitor_is_asked_again(): void
    {
        $this->get(route('home'))->assertDontSee(self::PREFETCH_SCRIPT, false);

        $this->actingAs(User::factory()->create())->get(route('home'))->assertSee(self::PREFETCH_SCRIPT, false);
    }

    /** The name of the built file of a page, as it appears in the list of files to fetch (without the folder, which JSON escapes). */
    private function builtFile(string $page): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        $this->assertArrayHasKey($page, $manifest, "{$page} is not in the built front end: run npm run build.");

        return basename($manifest[$page]['file']);
    }

    /** A page of the staff area that only staff can open, from the manifest of the build, so a page that is renamed does not matter. */
    private function staffOnlyPage(): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        foreach (array_keys($manifest) as $key) {
            if (str_starts_with($key, 'resources/js/pages/acp/') && ! in_array(basename($key, '.vue'), StaffAreaVite::OPEN_TO_MEMBERS, true)) {
                return $key;
            }
        }

        $this->fail('The built front end has no page in the staff area.');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('moderator'));

        return $user;
    }

    private function homePageOf(User $user): string
    {
        return (string) $this->actingAs($user)->get(route('home'))->assertOk()->assertSee(self::PREFETCH_SCRIPT, false)->getContent();
    }

    #[Test]
    public function a_member_who_is_not_staff_is_not_asked_to_fetch_the_staff_area(): void
    {
        $html = $this->homePageOf(User::factory()->create());

        $this->assertStringContainsString($this->builtFile('resources/js/pages/Blog.vue'), $html, 'A page of the site is not fetched.');
        $this->assertStringNotContainsString($this->builtFile($this->staffOnlyPage()), $html, 'A page of the staff area is fetched for a member.');
    }

    #[Test]
    public function the_page_in_the_staff_areas_folder_that_a_member_can_open_is_still_fetched_for_them(): void
    {
        $html = $this->homePageOf(User::factory()->create());

        $this->assertStringContainsString($this->builtFile('resources/js/pages/acp/ForumPostHistory.vue'), $html);
    }

    #[Test]
    public function staff_are_asked_to_fetch_the_staff_area_as_well(): void
    {
        $html = $this->homePageOf($this->staff());

        $this->assertStringContainsString($this->builtFile('resources/js/pages/Blog.vue'), $html);
        $this->assertStringContainsString($this->builtFile($this->staffOnlyPage()), $html, 'A page of the staff area is not fetched for staff.');
    }

    #[Test]
    public function a_member_served_after_staff_by_the_same_worker_is_not_asked_to_fetch_the_staff_area(): void
    {
        $this->homePageOf($this->staff());

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $html = $this->homePageOf(User::factory()->create());

        $this->assertStringNotContainsString($this->builtFile($this->staffOnlyPage()), $html, 'What staff were sent is still set for the next request.');
    }

    #[Test]
    public function staff_served_after_a_member_are_asked_to_fetch_the_staff_area_again(): void
    {
        $this->homePageOf(User::factory()->create());

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->assertStringContainsString($this->builtFile($this->staffOnlyPage()), $this->homePageOf($this->staff()));
    }

    #[Test]
    public function a_test_that_swaps_vite_for_a_fake_can_still_sign_in(): void
    {
        // `withoutVite()` binds an anonymous class that extends Laravel's Vite, not the app's. Many tests use it, and the middleware may
        // not insist on the app's class: it did once, and every one of them that signed in failed with a TypeError.
        $this->withoutVite();

        $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();
        $this->actingAs($this->staff())->get(route('home'))->assertOk();
    }

    #[Test]
    public function a_visitor_served_after_staff_is_not_asked_for_anything(): void
    {
        $this->homePageOf($this->staff());

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get(route('home'))->assertOk()->assertDontSee(self::PREFETCH_SCRIPT, false);
    }
}
