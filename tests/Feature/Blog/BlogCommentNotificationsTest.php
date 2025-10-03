<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\User;
use App\Notifications\BlogCommentPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BlogCommentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_subscribe_to_blog_comment_notifications(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->published()->create();

        $this->actingAs($user);

        $response = $this->postJson(route('blogs.comments.subscriptions.store', ['blog' => $blog->slug]));

        $response->assertOk()->assertJson([
            'subscribed' => true,
        ]);

        $this->assertDatabaseHas('blog_comment_subscriptions', [
            'blog_id' => $blog->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_unsubscribe_from_blog_comment_notifications(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->published()->create();

        $blog->commentSubscribers()->attach($user);

        $this->actingAs($user);

        $response = $this->deleteJson(route('blogs.comments.subscriptions.destroy', ['blog' => $blog->slug]));

        $response->assertOk()->assertJson([
            'subscribed' => false,
        ]);

        $this->assertDatabaseMissing('blog_comment_subscriptions', [
            'blog_id' => $blog->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_subscribers_receive_notification_when_comment_is_posted(): void
    {
        Notification::fake();

        $blog = Blog::factory()->published()->create();
        $subscriber = User::factory()->create();
        $anotherSubscriber = User::factory()->create();
        $commentAuthor = User::factory()->create();

        $blog->commentSubscribers()->attach([$subscriber->id, $anotherSubscriber->id]);

        $this->actingAs($commentAuthor);

        $response = $this->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'First!',
        ]);

        $response->assertCreated();

        $blog->refresh();
        $comment = $blog->comments()->latest()->first();

        $this->assertNotNull($comment);

        Notification::assertSentTo(
            $subscriber,
            BlogCommentPosted::class,
            function (BlogCommentPosted $notification) use ($blog, $comment, $subscriber) {
                $data = $notification->toArray($subscriber);

                return $data['blog_id'] === $blog->id
                    && $data['comment_id'] === $comment->id;
            }
        );

        Notification::assertSentTo(
            $anotherSubscriber,
            BlogCommentPosted::class,
            function (BlogCommentPosted $notification) use ($blog, $comment, $anotherSubscriber) {
                $data = $notification->toArray($anotherSubscriber);

                return $data['blog_id'] === $blog->id
                    && $data['comment_id'] === $comment->id;
            }
        );

        Notification::assertNotSentTo($commentAuthor, BlogCommentPosted::class);
    }
}
