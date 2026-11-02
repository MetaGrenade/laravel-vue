<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Only a signed-in user is asked to fetch the rest of the front end in the background once a page has drawn (about 990 kB gzipped, the
 * staff area included): a visitor often reads one page, and the fetch also puts a 71 kB script in every page they are sent
 * (app/Http/Middleware/PrefetchAssetsForMembers.php, docs/bundle-budget.md).
 */
class PrefetchAssetsTest extends TestCase
{
    use RefreshDatabase;

    /** A line of the script Laravel writes into a page for Vite::prefetch, which no other part of a page has. */
    private const PREFETCH_SCRIPT = 'const loadNext';

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
}
