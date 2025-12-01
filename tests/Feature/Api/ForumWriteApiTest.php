<?php

namespace Tests\Feature\Api;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForumWriteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'web']);
    }

    public function test_user_can_create_thread_with_initial_post(): void
    {
        Notification::fake();
        Event::fake();

        $board = $this->createBoard();
        $author = User::factory()->create();
        Sanctum::actingAs($author, ['*']);

        $payload = [
            'title' => 'API Created Thread',
            'body' => '<p>First content</p>',
        ];

        $response = $this->postJson(route('api.v1.forum.threads.store', $board), $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('thread.title', 'API Created Thread')
            ->assertJsonPath('initial_post.body', '<p>First content</p>');

        $this->assertDatabaseHas('forum_threads', [
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'API Created Thread',
        ]);

        $this->assertDatabaseHas('forum_posts', [
            'forum_thread_id' => ForumThread::first()->id,
            'user_id' => $author->id,
            'body' => '<p>First content</p>',
        ]);
    }

    public function test_user_can_reply_to_thread_and_updates_last_poster(): void
    {
        Notification::fake();
        Event::fake();

        $board = $this->createBoard();
        $threadAuthor = User::factory()->create();
        [$thread] = $this->createThreadWithPost($board, $threadAuthor);

        $replier = User::factory()->create();
        Sanctum::actingAs($replier, ['*']);

        $response = $this->postJson(
            route('api.v1.forum.posts.store', [$board, $thread]),
            ['body' => '<p>Second reply</p>']
        );

        $response
            ->assertCreated()
            ->assertJsonPath('body', '<p>Second reply</p>');

        $thread->refresh();

        $this->assertSame($replier->id, $thread->last_post_user_id);
        $this->assertDatabaseHas('forum_posts', [
            'forum_thread_id' => $thread->id,
            'user_id' => $replier->id,
            'body' => '<p>Second reply</p>',
        ]);
    }

    public function test_author_can_edit_post_and_revision_is_recorded(): void
    {
        Notification::fake();
        Event::fake();

        $board = $this->createBoard();
        $author = User::factory()->create();
        [$thread, $post] = $this->createThreadWithPost($board, $author, '<p>Original</p>');

        Sanctum::actingAs($author, ['*']);

        $response = $this->patchJson(
            route('api.v1.forum.posts.update', [$board, $thread, $post]),
            ['body' => '<p>Updated body</p>']
        );

        $response->assertOk();

        $post->refresh();

        $this->assertSame('<p>Updated body</p>', $post->body);
        $this->assertNotNull($post->edited_at);
        $this->assertDatabaseHas('forum_post_revisions', [
            'forum_post_id' => $post->id,
            'body' => '<p>Original</p>',
        ]);
    }

    public function test_user_can_toggle_thread_subscription(): void
    {
        $board = $this->createBoard();
        $author = User::factory()->create();
        [$thread] = $this->createThreadWithPost($board, $author);

        $subscriber = User::factory()->create();
        Sanctum::actingAs($subscriber, ['*']);

        $subscribeResponse = $this->postJson(route('api.v1.forum.threads.subscribe', [$board, $thread]));
        $subscribeResponse
            ->assertOk()
            ->assertExactJson(['subscribed' => true]);

        $this->assertDatabaseHas('forum_thread_subscriptions', [
            'forum_thread_id' => $thread->id,
            'user_id' => $subscriber->id,
        ]);

        $unsubscribeResponse = $this->deleteJson(route('api.v1.forum.threads.unsubscribe', [$board, $thread]));
        $unsubscribeResponse
            ->assertOk()
            ->assertExactJson(['subscribed' => false]);

        $this->assertDatabaseMissing('forum_thread_subscriptions', [
            'forum_thread_id' => $thread->id,
            'user_id' => $subscriber->id,
        ]);
    }

    public function test_moderator_can_lock_and_delete_thread(): void
    {
        $board = $this->createBoard();
        $author = User::factory()->create();
        [$thread] = $this->createThreadWithPost($board, $author);

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');
        Sanctum::actingAs($moderator, ['*']);

        $lockResponse = $this->patchJson(route('api.v1.forum.threads.lock', [$board, $thread]));
        $lockResponse
            ->assertOk()
            ->assertJsonPath('is_locked', true);

        $deleteResponse = $this->deleteJson(route('api.v1.forum.threads.destroy', [$board, $thread]));
        $deleteResponse->assertNoContent();

        $this->assertDatabaseMissing('forum_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_non_moderator_cannot_update_locked_thread_title(): void
    {
        $board = $this->createBoard();
        $author = User::factory()->create();
        [$thread] = $this->createThreadWithPost($board, $author);
        $thread->forceFill(['is_locked' => true])->save();

        Sanctum::actingAs($author, ['*']);

        $response = $this->patchJson(
            route('api.v1.forum.threads.update', [$board, $thread]),
            ['title' => 'Attempted update']
        );

        $response->assertForbidden();
    }

    public function test_non_moderator_cannot_subscribe_to_unpublished_thread(): void
    {
        $board = $this->createBoard();
        $author = User::factory()->create();
        [$thread] = $this->createThreadWithPost($board, $author);
        $thread->forceFill(['is_published' => false])->save();

        $subscriber = User::factory()->create();
        Sanctum::actingAs($subscriber, ['*']);

        $response = $this->postJson(route('api.v1.forum.threads.subscribe', [$board, $thread]));

        $response->assertForbidden();
    }

    public function test_moderator_can_publish_thread(): void
    {
        $board = $this->createBoard();
        $author = User::factory()->create();
        [$thread] = $this->createThreadWithPost($board, $author);
        $thread->forceFill(['is_published' => false])->save();

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');
        Sanctum::actingAs($moderator, ['*']);

        $response = $this->patchJson(route('api.v1.forum.threads.publish', [$board, $thread]));

        $response
            ->assertOk()
            ->assertJsonPath('is_published', true);

        $thread->refresh();

        $this->assertTrue($thread->is_published);
    }

    private function createBoard(): ForumBoard
    {
        $category = ForumCategory::create([
            'title' => 'General',
            'slug' => 'general',
            'description' => 'General discussion',
            'position' => 1,
        ]);

        return ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => 'announcements',
            'description' => 'Announcements board',
            'position' => 1,
        ]);
    }

    private function createThreadWithPost(ForumBoard $board, User $author, string $body = '<p>Body</p>'): array
    {
        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Thread Title',
            'slug' => Str::slug('thread-'.$author->id.'-'.Str::random(6)),
            'excerpt' => Str::limit(strip_tags($body), 160),
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);

        $thread->forceFill([
            'last_posted_at' => $post->created_at,
            'last_post_user_id' => $post->user_id,
        ])->save();

        return [$thread, $post];
    }
}
