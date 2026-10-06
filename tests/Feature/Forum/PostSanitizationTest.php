<?php

namespace Tests\Feature\Forum;

use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_html_is_sanitised_before_it_is_stored(): void
    {
        [$board, $thread] = $this->createForumContext();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('forum.posts.store', [$board, $thread]), [
            'body' => '<p>Hello <img src=x onerror="alert(document.cookie)"></p><script>alert(1)</script>',
        ])->assertRedirect();

        $post = ForumPost::query()->where('user_id', $user->id)->sole();

        $this->assertStringContainsString('Hello', $post->body);
        $this->assertStringNotContainsString('onerror', $post->body);
        $this->assertStringNotContainsString('<script', $post->body);
        $this->assertStringNotContainsString('<img', $post->body);
    }

    public function test_reply_consisting_only_of_unsafe_markup_is_rejected(): void
    {
        [$board, $thread] = $this->createForumContext();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('forum.posts.store', [$board, $thread]), [
            'body' => '<script>alert("x")</script>',
        ])->assertSessionHasErrors('body');

        $this->assertDatabaseMissing('forum_posts', ['user_id' => $user->id]);
    }

    public function test_new_thread_body_is_sanitised(): void
    {
        [$board] = $this->createForumContext();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('forum.threads.store', $board), [
            'title' => 'A perfectly normal thread',
            'body' => '<p onmouseover="steal()">Body</p><iframe src="https://evil.example"></iframe>',
        ])->assertRedirect();

        $post = ForumPost::query()->where('user_id', $user->id)->sole();

        $this->assertSame('<p>Body</p>', $post->body);
    }

    public function test_model_cast_sanitises_every_write_path(): void
    {
        [, $thread] = $this->createForumContext();

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'body' => '<p>ok</p><a href="javascript:alert(1)">x</a>',
        ]);

        $this->assertStringNotContainsString('javascript:', $post->fresh()->body);
    }

    public function test_blog_body_is_sanitised_with_article_policy(): void
    {
        $blog = Blog::factory()->create([
            'body' => '<h2>Heading</h2><p style="color:red">Text</p><script>alert(1)</script>',
        ]);

        $body = $blog->fresh()->body;

        $this->assertStringContainsString('<h2>Heading</h2>', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('style=', $body);
    }

    public function test_sanitize_command_cleans_existing_records(): void
    {
        [, $thread] = $this->createForumContext();

        $id = ForumPost::query()->insertGetId([
            'forum_thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'body' => '<p>legacy</p><script>alert(1)</script>',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('content:sanitize')->assertSuccessful();

        $this->assertSame('<p>legacy</p>', ForumPost::find($id)->body);
    }

    /**
     * @return array{0: ForumBoard, 1: ForumThread}
     */
    private function createForumContext(): array
    {
        $category = ForumCategory::create([
            'title' => 'General',
            'slug' => Str::slug('General'),
            'description' => 'General discussion',
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
            'body' => '<p>Thread opener</p>',
        ]);

        return [$board, $thread];
    }
}
