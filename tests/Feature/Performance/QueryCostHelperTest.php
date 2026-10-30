<?php

namespace Tests\Feature\Performance;

use App\Models\Blog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CountsQueries;
use Tests\TestCase;

/**
 * The check that every page in {@see QueryBudgetTest} relies on has to be able to fail, or a green run says nothing.
 */
class QueryCostHelperTest extends TestCase
{
    use CountsQueries;
    use RefreshDatabase;

    /** A page that asks for its blogs, and then for the author of each one. */
    private function pageThatAsksPerRow(): \Closure
    {
        return function (): void {
            foreach (Blog::query()->get() as $blog) {
                DB::table('users')->where('id', $blog->user_id)->first();
            }
        };
    }

    #[Test]
    public function a_page_that_asks_once_per_row_fails_and_says_what_it_asked(): void
    {
        try {
            $this->assertQueryCost('the page', fn () => Blog::factory()->published()->create(), $this->pageThatAsksPerRow(), 50);
            $this->fail('A page that asks a query per row was accepted.');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('the page asked 2 queries with one row and 7 with 6, so it asks something once per row', $failure->getMessage());
            $this->assertMatchesRegularExpression('/5x select \* from \W?users\W? where \W?id\W? = \? limit 1/', $failure->getMessage(), 'names are quoted as the database quotes them');
        }
    }

    #[Test]
    public function a_page_over_its_ceiling_fails_even_though_it_does_not_grow(): void
    {
        $page = fn () => DB::table('users')->count() + DB::table('blogs')->count() + DB::table('faqs')->count();

        $this->assertQueryCost('the page', fn () => null, $page, 3);

        try {
            $this->assertQueryCost('the page', fn () => null, $page, 2);
            $this->fail('A page over its ceiling was accepted.');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('the page asked 3 queries with one row, more than the 2 it is allowed', $failure->getMessage());
        }
    }

    #[Test]
    public function a_page_that_asks_the_same_whatever_the_rows_passes(): void
    {
        $page = fn () => Blog::query()->with('user')->get();

        $this->assertQueryCost('the page', fn () => Blog::factory()->published()->create(), $page, 2);
    }

    #[Test]
    public function the_cache_is_empty_for_each_measurement_so_a_cache_hit_cannot_hide_the_cost(): void
    {
        $page = fn () => Cache::remember('the-page', 600, fn () => Blog::query()->count());

        $this->assertSame(1, count($this->queriesRun($page)));
        $this->assertSame(1, count($this->queriesRun($page)), 'the second request would have been answered from the cache');
    }

    #[Test]
    public function lists_of_ids_do_not_make_two_requests_differ(): void
    {
        $this->assertSame(
            $this->queriesRun(fn () => DB::select('select * from blogs where id in (1, 2)')),
            $this->queriesRun(fn () => DB::select('select * from blogs where id in (3, 4, 5, 6)')),
        );
    }
}
