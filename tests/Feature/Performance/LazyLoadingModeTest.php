<?php

namespace Tests\Feature\Performance;

use App\Models\Blog;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The suite fails a test that loads a relation one model at a time out of a list (Tests\TestCase, docs/query-budgets.md).
 */
class LazyLoadingModeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reading_a_relation_of_each_model_in_a_list_fails(): void
    {
        Blog::factory()->count(2)->published()->create();

        $blogs = Blog::query()->get();

        try {
            $blogs->each(fn (Blog $blog) => $blog->user);
            $this->fail('A relation read from each model of a list was not refused.');
        } catch (LazyLoadingViolationException $violation) {
            $this->assertStringContainsString('Blog', $violation->getMessage());
            $this->assertStringContainsString('user', $violation->getMessage());
        } finally {
            $this->lazyLoads = []; // done on purpose: this test is the one that checks the mode
        }
    }

    #[Test]
    public function a_failure_that_the_code_catches_is_still_noted_for_the_test(): void
    {
        Blog::factory()->count(2)->published()->create();

        $blogs = Blog::query()->get();

        try {
            $blogs->first()->user; // a page that catches Throwable around a query would swallow this
        } catch (\Throwable) {
        }

        $this->assertSame(['Blog::user'], $this->lazyLoads, 'tearDown fails the test with this');
        $this->lazyLoads = [];
    }

    #[Test]
    public function the_same_list_loaded_with_its_relation_passes(): void
    {
        Blog::factory()->count(2)->published()->create();

        $names = Blog::query()->with('user')->get()->map(fn (Blog $blog) => $blog->user->nickname);

        $this->assertCount(2, $names);
        $this->assertSame([], $this->lazyLoads);
    }

    #[Test]
    public function a_single_model_may_load_what_it_needs(): void
    {
        $blog = Blog::factory()->published()->create();

        $this->assertNotNull(Blog::query()->findOrFail($blog->id)->user, 'one query for one model, nothing to batch');
        $this->assertSame([], $this->lazyLoads);
    }
}
