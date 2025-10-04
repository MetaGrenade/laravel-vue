<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostMentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_mentions_and_notifies_existing_users_only(): void
    {
        Notification::fake();

        [$board, $thread] = $this->createForumContext();

        $replier = User::factory()->create();
        $mentionedUser = User::factory()->create(['nickname' => 'MentionTarget']);
        $unrelatedUser = User::factory()->create();

        $body = '<p>Hello @' . $mentionedUser->nickname . ' and @ghost-user</p>';

        $response = $this->actingAs($replier)->post(route('forum.posts.store', [$board, $thread]), [
            'body' => $body,
        ]);

        $response->assertRedirect();

        $post = ForumPost::query()->where('forum_thread_id', $thread->id)->latest('id')->first();
        $this->assertNotNull($post);

        $this->assertDatabaseHas('forum_post_mentions', [
            'forum_post_id' => $post->id,
            'mentioned_user_id' => $mentionedUser->id,
        ]);

        $this->assertDatabaseCount('forum_post_mentions', 1);

        Notification::assertSentTo(
            $mentionedUser,
            ForumPostMentioned::class,
            function (ForumPostMentioned $notification) use ($thread, $post, $mentionedUser) {
                $data = $notification->toArray($mentionedUser);

                return $data['thread_id'] === $thread->id && $data['post_id'] === $post->id;
            }
        );

        Notification::assertNotSentTo($replier, ForumPostMentioned::class);
        Notification::assertNotSentTo($unrelatedUser, ForumPostMentioned::class);
    }

    public function test_update_notifies_only_new_mentions_and_syncs_records(): void
    {
        Notification::fake();

        [$board, $thread] = $this->createForumContext();

        $author = $thread->author;
        $existingMention = User::factory()->create(['nickname' => 'ExistingUser']);
        $newMention = User::factory()->create(['nickname' => 'NewUser']);

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => '<p>Initial content @' . $existingMention->nickname . '</p>',
        ]);

        $post->mentions()->attach($existingMention->id);

        $updatedBody = '<p>Update for @' . $existingMention->nickname . ' and welcome @' . $newMention->nickname . ' plus @unknown</p>';

        $response = $this->actingAs($author)->put(route('forum.posts.update', [$board, $thread, $post]), [
            'body' => $updatedBody,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('forum_post_mentions', [
            'forum_post_id' => $post->id,
            'mentioned_user_id' => $existingMention->id,
        ]);

        $this->assertDatabaseHas('forum_post_mentions', [
            'forum_post_id' => $post->id,
            'mentioned_user_id' => $newMention->id,
        ]);

        $this->assertDatabaseCount('forum_post_mentions', 2);

        Notification::assertNotSentTo($existingMention, ForumPostMentioned::class);

        Notification::assertSentTo(
            $newMention,
            ForumPostMentioned::class,
            function (ForumPostMentioned $notification) use ($thread, $post, $newMention) {
                $data = $notification->toArray($newMention);

                return $data['thread_id'] === $thread->id && $data['post_id'] === $post->id;
            }
        );
    }

    public function test_database_channel_is_persisted_while_mail_is_queued(): void
    {
        Queue::fake();

        [$board, $thread] = $this->createForumContext();

        $replier = User::factory()->create();
        $mentionedUser = User::factory()->create(['nickname' => 'MentionTarget']);

        $response = $this->actingAs($replier)->post(route('forum.posts.store', [$board, $thread]), [
            'body' => '<p>Hello @' . $mentionedUser->nickname . '</p>',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $mentionedUser->id,
            'notifiable_type' => User::class,
        ]);

        Queue::assertPushedOn('mail', SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($mentionedUser) {
            return $job->notification instanceof ForumPostMentioned
                && $job->notifiables->count() === 1
                && optional($job->notifiables->first())->is($mentionedUser)
                && $job->channels === ['mail'];
        });
    }

//    public function test_mention_suggestions_returns_matching_users(): void
//    {
//        $requester = User::factory()->create();
//        $match = User::factory()->create(['nickname' => 'TargetUser']);
//        $other = User::factory()->create(['nickname' => 'AnotherMember']);
//
//        $response = $this->actingAs($requester)
//            ->getJson(route('forum.mentions.index', ['q' => 'Target']));
//
//        $response->assertOk();
//        $response->assertJson(fn ($json) => $json
//            ->has('data', 1)
//            ->first(fn ($jsonItem) => $jsonItem
//                ->where('id', $match->id)
//                ->where('nickname', $match->nickname)
//                ->etc(), 'data'));
//
//        $response->assertJsonMissing(['id' => $requester->id]);
//        $response->assertJsonMissing(['id' => $other->id]);
//    }

    public function test_mention_suggestions_require_authentication(): void
    {
        $response = $this->get(route('forum.mentions.index', ['q' => 'anyone']));

        $response->assertRedirect(route('login'));
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
            'body' => '<p>Thread opener</p>',
        ]);

        $thread->setRelation('author', $author);

        return [$board, $thread];
    }
}
