<?php

namespace Tests\Feature\Database;

use App\Models\AutomodRule;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The automatic moderation rules, and the held state of forum content, on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends.
 */
class AutomodMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_01_000000_create_automod_rules_and_hold_forum_content.php');
    }

    #[Test]
    public function the_table_and_the_columns_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('automod_rules', ['name', 'type', 'settings', 'action', 'only_new_days', 'is_enabled', 'hits', 'last_hit_at']));

        foreach (['forum_posts', 'forum_threads'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['held_at', 'held_reason', 'held_by_rule_id']), $table);
            $this->assertTrue(Schema::hasIndex($table, ['held_at']), "the queue is read by {$table}.held_at");
        }

        try {
            $this->migration()->down();
            $this->assertFalse(Schema::hasTable('automod_rules'));
            $this->assertFalse(Schema::hasColumn('forum_posts', 'held_at'));
            $this->assertFalse(Schema::hasColumn('forum_threads', 'held_at'));

            $this->migration()->up();
            $this->assertTrue(Schema::hasTable('automod_rules'));
            $this->assertTrue(Schema::hasColumn('forum_posts', 'held_by_rule_id'));
        } finally {
            if (! Schema::hasTable('automod_rules')) {
                $this->migration()->up();
            }
        }
    }

    #[Test]
    public function deleting_a_rule_keeps_what_it_held_and_why(): void
    {
        $author = User::factory()->create();
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Lounge', 'slug' => 'lounge', 'description' => 'Chat', 'position' => 1]);
        $thread = ForumThread::create(['forum_board_id' => $board->id, 'user_id' => $author->id, 'title' => 'Held', 'slug' => 'held', 'excerpt' => 'Held', 'is_published' => false]);
        $thread->forceFill(['held_at' => now(), 'held_reason' => 'Rule: why'])->save();

        $rule = AutomodRule::factory()->create();
        $post = new ForumPost(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => '<p>Hi</p>']);
        $post->forceFill(['held_at' => now(), 'held_reason' => 'Rule: why', 'held_by_rule_id' => $rule->id])->save();
        $thread->forceFill(['held_by_rule_id' => $rule->id])->save();

        $rule->delete();

        $post = ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->sole();
        $this->assertNull($post->held_by_rule_id);
        $this->assertSame('Rule: why', $post->held_reason);
        $this->assertNotNull($post->held_at, 'still held, and still waiting for a moderator');
        $this->assertNull($thread->fresh()->held_by_rule_id);
        $this->assertSame('Rule: why', $thread->fresh()->held_reason);
    }
}
