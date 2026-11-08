<?php

namespace Tests\Feature\Api;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A user in the API is `id` and `nickname` (and `email` for the key's own owner). The `users.name` column became `nickname`, and for a
 * while every author, the profile and the sign-in answer said `"name": null`.
 */
class UserNicknameApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'web']);
    }

    private function member(string $nickname): User
    {
        return User::factory()->create(['nickname' => $nickname, 'email' => $nickname.'@example.com']);
    }

    private function board(): ForumBoard
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'General discussion', 'position' => 1]);

        return ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Announcements', 'slug' => 'announcements', 'description' => 'Announcements board', 'position' => 1]);
    }

    /** A thread started by one member, with a later reply from another, so the thread's author and its latest post's author differ. */
    private function threadWithReply(User $starter, User $replier): ForumThread
    {
        $thread = ForumThread::create([
            'forum_board_id' => $this->board()->id,
            'user_id' => $starter->id,
            'title' => 'Thread Title',
            'slug' => 'thread-title',
            'excerpt' => 'First',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $starter->id,
        ]);

        ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $starter->id, 'body' => '<p>First</p>', 'created_at' => now()->subHour()]);
        $reply = ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $replier->id, 'body' => '<p>Reply</p>', 'created_at' => now()]);

        $thread->forceFill(['last_posted_at' => $reply->created_at, 'last_post_user_id' => $replier->id])->save();

        return $thread;
    }

    // --- Authors ------------------------------------------------------------------------------------------------------------------------

    public function test_the_author_of_a_blog_post_has_a_nickname_in_the_list_and_on_the_post(): void
    {
        $author = $this->member('quill');
        $blog = Blog::factory()->published()->create(['user_id' => $author->id]);

        $this->getJson(route('api.v1.blogs.index'))
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $author->id)
            ->assertJsonPath('data.0.author.nickname', 'quill')
            ->assertJsonMissingPath('data.0.author.name');

        $this->getJson(route('api.v1.blogs.show', $blog))
            ->assertOk()
            ->assertJsonPath('data.author.id', $author->id)
            ->assertJsonPath('data.author.nickname', 'quill')
            ->assertJsonMissingPath('data.author.name');
    }

    public function test_the_author_of_a_blog_comment_has_a_nickname(): void
    {
        $blog = Blog::factory()->published()->create();
        $commenter = $this->member('chatty');
        BlogComment::factory()->for($blog)->create(['user_id' => $commenter->id, 'status' => BlogComment::STATUS_APPROVED]);

        $this->getJson(route('api.v1.blogs.comments.index', $blog))
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $commenter->id)
            ->assertJsonPath('data.0.user.nickname', 'chatty')
            ->assertJsonMissingPath('data.0.user.name');
    }

    public function test_the_author_of_a_thread_and_of_its_latest_post_have_nicknames_in_the_list_and_on_the_thread(): void
    {
        $thread = $this->threadWithReply($this->member('starter'), $this->member('replier'));

        $this->getJson(route('api.v1.forum.threads.index'))
            ->assertOk()
            ->assertJsonPath('data.0.author.nickname', 'starter')
            ->assertJsonMissingPath('data.0.author.name')
            ->assertJsonPath('data.0.latest_post.author.nickname', 'replier')
            ->assertJsonMissingPath('data.0.latest_post.author.name');

        $this->getJson(route('api.v1.forum.threads.show', $thread))
            ->assertOk()
            ->assertJsonPath('data.author.nickname', 'starter')
            ->assertJsonMissingPath('data.author.name')
            ->assertJsonPath('data.latest_post.author.nickname', 'replier')
            ->assertJsonMissingPath('data.latest_post.author.name');
    }

    public function test_the_author_of_a_reply_that_was_just_made_has_a_nickname(): void
    {
        Notification::fake();
        Event::fake();

        $thread = $this->threadWithReply($this->member('starter'), $this->member('replier'));
        $board = $thread->board;

        Sanctum::actingAs($this->member('newcomer'), ['*']);

        $this->postJson(route('api.v1.forum.posts.store', [$board, $thread]), ['body' => '<p>Me too</p>'])
            ->assertCreated()
            ->assertJsonPath('author.nickname', 'newcomer')
            ->assertJsonMissingPath('author.name');
    }

    public function test_only_the_key_owner_sees_an_email_next_to_a_nickname(): void
    {
        $thread = $this->threadWithReply($this->member('starter'), $this->member('replier'));

        // Anyone, with no key at all.
        $this->getJson(route('api.v1.forum.threads.show', $thread))
            ->assertOk()
            ->assertJsonMissingPath('data.author.email')
            ->assertJsonMissingPath('data.latest_post.author.email');

        // Somebody else holding a key.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->member('reader'), ['*']);

        $this->getJson(route('api.v1.forum.threads.show', $thread))
            ->assertOk()
            ->assertJsonMissingPath('data.author.email')
            ->assertJsonMissingPath('data.latest_post.author.email');
    }

    // --- The person holding the key ----------------------------------------------------------------------------------------------------

    public function test_the_profile_has_the_owners_nickname_and_email(): void
    {
        $owner = $this->member('jane');
        Sanctum::actingAs($owner, ['profile:read']);

        $this->getJson(route('api.v1.profile.show'))
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $owner->id, 'nickname' => 'jane', 'email' => 'jane@example.com']]);
    }

    public function test_the_older_address_of_the_profile_says_the_same(): void
    {
        $owner = $this->member('jane');
        Sanctum::actingAs($owner, ['profile:read']);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $owner->id, 'nickname' => 'jane', 'email' => 'jane@example.com']]);
    }

    public function test_signing_in_answers_with_the_nickname(): void
    {
        $user = $this->member('jane');

        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])
            ->assertCreated()
            ->assertJsonPath('user', ['id' => $user->id, 'nickname' => 'jane', 'email' => 'jane@example.com'])
            ->assertJsonMissingPath('user.name');
    }

    public function test_a_key_made_by_signing_in_reads_the_same_profile(): void
    {
        $user = $this->member('jane');

        $secret = $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])->assertCreated()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson(route('api.v1.profile.show'))
            ->assertOk()
            ->assertJsonPath('data.nickname', 'jane');
    }
}
