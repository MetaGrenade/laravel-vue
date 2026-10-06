<?php

namespace Tests\Feature\Database;

use App\Models\Blog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rolling the blog status migration back must work after a post has used the
 * 'scheduled' status the migration introduces. This runs on every database CI
 * supports: PostgreSQL rejects the old CHECK constraint if such rows remain.
 */
class BlogStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2025_05_06_010000_update_blog_status_enum_for_scheduling.php');
    }

    #[Test]
    public function rolling_back_turns_scheduled_posts_into_drafts_and_succeeds(): void
    {
        $migration = $this->migration();
        $scheduled = Blog::factory()->scheduled()->create();
        $published = Blog::factory()->published()->create();

        try {
            $migration->down();

            $this->assertSame('draft', $scheduled->fresh()->status);
            $this->assertSame('published', $published->fresh()->status);
        } finally {
            // Restore the schema this test run (and the rest of the suite) expects.
            $migration->up();
        }

        // The status is accepted again once the migration is re-applied.
        $again = Blog::factory()->scheduled()->create();

        $this->assertSame('scheduled', $again->fresh()->status);

        Blog::query()->whereKey([$scheduled->id, $published->id, $again->id])->delete();
    }
}
