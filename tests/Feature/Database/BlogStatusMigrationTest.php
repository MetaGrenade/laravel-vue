<?php

namespace Tests\Feature\Database;

use App\Models\Blog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function postgresMigration(): object
    {
        return require database_path('migrations/2026_10_08_000100_allow_scheduled_blog_status_on_postgresql.php');
    }

    #[Test]
    public function the_postgresql_migration_upgrades_a_database_that_still_has_the_old_constraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The blogs.status CHECK constraint only exists on PostgreSQL.');
        }

        $migration = $this->postgresMigration();

        // Recreate an existing installation: the 2025 migration was already
        // recorded as run, but it never widened the three-value constraint.
        $migration->down();

        try {
            // A failed statement aborts a PostgreSQL transaction, so try it in a savepoint.
            DB::transaction(fn () => Blog::factory()->scheduled()->create());

            $this->fail('The legacy constraint should reject the scheduled status.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('blogs_status_check', $exception->getMessage());
        }

        // Deploying the new migration fixes it...
        $migration->up();

        $scheduled = Blog::factory()->scheduled()->create();
        $this->assertSame('scheduled', $scheduled->fresh()->status);

        // ...it can safely run again...
        $migration->up();

        // ...and rolling it back with a scheduled post present succeeds.
        $migration->down();
        $this->assertSame('draft', $scheduled->fresh()->status);

        // Leave the schema as the rest of the suite expects it.
        $migration->up();
        $scheduled->delete();
    }

    #[Test]
    public function the_postgresql_migration_does_nothing_on_other_databases(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('Covered by the PostgreSQL upgrade test.');
        }

        $blog = Blog::factory()->scheduled()->create();
        $migration = $this->postgresMigration();

        $migration->up();
        $migration->down();

        // Untouched: no status normalisation outside PostgreSQL.
        $this->assertSame('scheduled', $blog->fresh()->status);
    }
}
