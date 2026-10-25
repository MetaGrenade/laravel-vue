<?php

namespace Tests\Feature\Database;

use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The moderation history and the columns of a ban on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends.
 */
class ModerationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_31_000000_create_moderation_actions_table.php');
    }

    #[Test]
    public function the_table_and_the_columns_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('moderation_actions', [
            'user_id', 'actor_id', 'type', 'reason', 'note', 'expires_at', 'subject_type', 'subject_id', 'subject_label', 'notified', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('moderation_actions', 'updated_at'), 'a row is never changed');
        $this->assertTrue(Schema::hasColumns('users', ['banned_until', 'ban_reason']));

        try {
            $this->migration()->down();
            $this->assertFalse(Schema::hasTable('moderation_actions'));
            $this->assertFalse(Schema::hasColumn('users', 'banned_until'));
            $this->assertFalse(Schema::hasColumn('users', 'ban_reason'));

            $this->migration()->up();
            $this->assertTrue(Schema::hasTable('moderation_actions'));
            $this->assertTrue(Schema::hasColumns('users', ['banned_until', 'ban_reason']));
        } finally {
            if (! Schema::hasTable('moderation_actions')) {
                $this->migration()->up();
            }
        }
    }

    #[Test]
    public function a_date_far_ahead_is_kept_as_it_was_written(): void
    {
        // A date, not a timestamp: a ban of a few decades is not cut off at 2038.
        $member = User::factory()->create();
        $member->forceFill(['is_banned' => true, 'banned_until' => '2099-12-31 23:59:59'])->save();

        $this->assertSame('2099-12-31 23:59:59', $member->fresh()->banned_until->format('Y-m-d H:i:s'));

        $action = ModerationAction::factory()->ban(now()->setDate(2099, 12, 31)->setTime(23, 59, 59))->create(['user_id' => $member->id]);
        $this->assertSame('2099-12-31 23:59:59', $action->fresh()->expires_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_history_goes_with_the_member_and_outlives_the_moderator_at_the_database_level(): void
    {
        $member = User::factory()->create();
        $moderator = User::factory()->create();
        ModerationAction::factory()->create(['user_id' => $member->id, 'actor_id' => $moderator->id]);

        $moderator->delete();
        $this->assertSame(1, ModerationAction::query()->count(), 'the moderator going does not take what they did');
        $this->assertNull(ModerationAction::query()->sole()->actor_id);

        $member->delete();
        $this->assertSame(0, ModerationAction::query()->count(), 'the member going takes their history');
    }
}
