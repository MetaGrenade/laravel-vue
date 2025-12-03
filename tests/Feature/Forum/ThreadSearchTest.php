<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as AssertableInertia;
use Tests\TestCase;

class ThreadSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_thread_body_and_latest_post(): void
    {
        $board = $this->createBoard();
        $author = User::factory()->create(['nickname' => 'Starter']);
        $responder = User::factory()->create(['nickname' => 'Responder']);

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Interesting topic',
            'slug' => Str::slug('Interesting topic'),
            'excerpt' => 'A short introduction',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now()->subHour(),
            'last_post_user_id' => $author->id,
        ]);

        ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => 'First post content mentioning blueberries.',
            'created_at' => now()->subHours(2),
        ]);

        $latestPost = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $responder->id,
            'body' => 'Latest reply mentioning strawberries.',
            'created_at' => now()->subHour(),
        ]);

        $thread->forceFill([
            'last_posted_at' => $latestPost->created_at,
            'last_post_user_id' => $responder->id,
        ])->save();

        $response = $this->get(route('forum.boards.show', ['board' => $board->slug, 'search' => 'strawberries']));

        $response->assertOk()->assertInertia(function (AssertableInertia $page) use ($thread) {
            $page->component('ForumThreads')
                ->where('threads.data', function (array $threads) use ($thread) {
                    return collect($threads)->contains(fn (array $item) => $item['id'] === $thread->id);
                });
        });
    }

    public function test_search_matches_thread_author_name(): void
    {
        $board = $this->createBoard();
        $author = User::factory()->create(['nickname' => 'Searchable Sage']);

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Authored thread',
            'slug' => Str::slug('Authored thread'),
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);

        ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => 'Content without keywords.',
        ]);

        $response = $this->get(route('forum.boards.show', ['board' => $board->slug, 'search' => 'Searchable']));

        $response->assertOk()->assertInertia(function (AssertableInertia $page) use ($thread) {
            $page->component('ForumThreads')
                ->where('threads.data', function (array $threads) use ($thread) {
                    return collect($threads)->contains(fn (array $item) => $item['id'] === $thread->id);
                })
                ->where('filters.search', 'Searchable');
        });
    }

    private function createBoard(): ForumBoard
    {
        $category = ForumCategory::create([
            'title' => 'General',
            'slug' => Str::slug('General'),
            'description' => 'General discussions',
            'position' => 1,
        ]);

        return ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => Str::slug('Announcements'),
            'description' => 'Forum announcements',
            'position' => 1,
        ]);
    }
}
