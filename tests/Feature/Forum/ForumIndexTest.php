<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ForumIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_forum_index_filters_results_by_search_term(): void
    {
        $category = ForumCategory::create([
            'title' => 'Community',
            'slug' => Str::slug('Community'),
            'description' => 'All community boards',
            'position' => 1,
        ]);

        $generalBoard = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'General Chat',
            'slug' => Str::slug('General Chat'),
            'description' => 'Talk about anything here',
            'position' => 1,
        ]);

        $supportBoard = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Support Desk',
            'slug' => Str::slug('Support Desk'),
            'description' => 'Get help from the community',
            'position' => 2,
        ]);

        $user = User::factory()->create();

        $generalThread = ForumThread::create([
            'forum_board_id' => $generalBoard->id,
            'user_id' => $user->id,
            'title' => 'Welcome Everyone',
            'slug' => Str::slug('Welcome Everyone'),
            'excerpt' => 'Say hello to the community',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 5,
            'last_posted_at' => now()->subDay(),
            'last_post_user_id' => $user->id,
        ]);

        $supportThread = ForumThread::create([
            'forum_board_id' => $supportBoard->id,
            'user_id' => $user->id,
            'title' => 'Support Tips and Tricks',
            'slug' => Str::slug('Support Tips and Tricks'),
            'excerpt' => 'How to solve common issues',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 15,
            'last_posted_at' => now(),
            'last_post_user_id' => $user->id,
        ]);

        ForumPost::create([
            'forum_thread_id' => $generalThread->id,
            'user_id' => $user->id,
            'body' => 'General welcome message',
        ]);

        ForumPost::create([
            'forum_thread_id' => $supportThread->id,
            'user_id' => $user->id,
            'body' => 'Helpful support content',
        ]);

        $response = $this->get(route('forum.index', ['search' => 'Support']));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Forum')
            ->where('filters.search', 'Support')
            ->where('defaultBoard.slug', $generalBoard->slug)
            ->has('categories', 1)
            ->where('categories.0.boards', fn (array $boards) => count($boards) === 1 && $boards[0]['slug'] === $supportBoard->slug)
            ->where('trendingThreads', fn (array $threads) => count($threads) === 1 && $threads[0]['slug'] === $supportThread->slug)
            ->where('latestPosts', fn (array $posts) => count($posts) === 1 && $posts[0]['thread_slug'] === $supportThread->slug)
        );
    }
}
