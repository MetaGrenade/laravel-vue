<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PostQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_html_is_sanitized_in_thread_view(): void
    {
        [$board, $thread] = $this->createForumContext();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->get(route('forum.threads.show', [$board, $thread]));

        $response
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                return $page
                    ->component('ForumThreadView')
                    ->has('posts.data', 1, function (AssertableInertia $post) {
                        return $post
                            ->where('quote_html', function (string $quoteHtml) {
                                $this->assertStringContainsString('<blockquote>', $quoteHtml);
                                $this->assertStringContainsString('Hello there!', strip_tags($quoteHtml));
                                $this->assertStringNotContainsString('<script', $quoteHtml);

                                return true;
                            })
                            ->etc();
                    });
            });

        $page = $response->viewData('page');
        $quoteHtml = $page['props']['posts']['data'][0]['quote_html'] ?? '';

        $this->assertNotSame('', $quoteHtml);
        $this->assertStringContainsString('<blockquote>', $quoteHtml);
        $this->assertStringContainsString('<p></p>', $quoteHtml);
        $this->assertStringNotContainsString('<script', $quoteHtml);
    }

    public function test_quote_markup_is_preserved_when_reply_is_submitted(): void
    {
        [$board, $thread] = $this->createForumContext();
        $replier = User::factory()->create();

        $page = $this->actingAs($replier)
            ->get(route('forum.threads.show', [$board, $thread]))
            ->viewData('page');

        $quoteHtml = $page['props']['posts']['data'][0]['quote_html'] ?? '';
        $this->assertNotSame('', $quoteHtml);

        $replyBody = $quoteHtml . '<p>Thanks for the insight.</p>';

        $response = $this->actingAs($replier)->post(route('forum.posts.store', [$board, $thread]), [
            'body' => $replyBody,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('forum_posts', [
            'forum_thread_id' => $thread->id,
            'body' => $replyBody,
        ]);
    }

    /**
     * @return array{ForumBoard, ForumThread}
     */
    private function createForumContext(): array
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

        $author = User::factory()->create();

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Thread Title',
            'slug' => Str::slug('Thread Title'),
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
            'body' => "<p>Hello there!</p><script>alert('bad');</script><p>Keep the discussion civil.</p>",
        ]);

        return [$board, $thread];
    }
}
