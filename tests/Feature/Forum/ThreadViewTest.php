<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThreadViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_thread_view_count_increments_when_viewed(): void
    {
        $category = ForumCategory::create([
            'title' => 'General',
            'slug' => Str::slug('General'),
            'description' => 'General discussions',
            'position' => 1,
        ]);

        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => Str::slug('Announcements'),
            'description' => 'Forum announcements',
            'position' => 1,
        ]);

        $user = User::factory()->create();

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $user->id,
            'title' => 'Thread Title',
            'slug' => Str::slug('Thread Title'),
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $user->id,
        ]);

        ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $user->id,
            'body' => 'Initial post content.',
        ]);

        $this->get(route('forum.threads.show', [$board, $thread]))
            ->assertOk();

        $this->assertSame(1, $thread->fresh()->views);

        $this->get(route('forum.threads.show', [$board, $thread]))
            ->assertOk();

        $this->assertSame(1, $thread->fresh()->views);
    }
}
