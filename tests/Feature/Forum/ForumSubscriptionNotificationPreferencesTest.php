<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Notifications\ForumThreadUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ForumSubscriptionNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_forum_subscription_notification_preferences_are_respected(): void
    {
        Notification::fake();

        [$board, $thread] = $this->createForumContext();

        $subscriber = User::factory()->create([
            'email_verified_at' => now(),
            'notification_preferences' => [
                'forum_subscription' => [
                    'mail' => true,
                    'push' => true,
                    'database' => false,
                ],
            ],
        ]);

        $thread->subscribers()->attach($subscriber->id);

        $replier = User::factory()->create();

        $this->actingAs($replier)
            ->post(route('forum.posts.store', [$board, $thread]), [
                'body' => '<p>Subscribed update</p>',
            ])
            ->assertRedirect();

        Notification::assertSentToTimes($subscriber, ForumThreadUpdated::class, 2);

        Notification::assertSentTo(
            $subscriber,
            ForumThreadUpdated::class,
            function (ForumThreadUpdated $notification, array $channels) {
                sort($channels);

                return $channels === ['broadcast'];
            }
        );

        Notification::assertSentTo(
            $subscriber,
            ForumThreadUpdated::class,
            function (ForumThreadUpdated $notification, array $channels) {
                return $channels === ['mail'];
            }
        );
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

        return [$board, $thread];
    }
}
