<?php

namespace Tests\Feature\Blog;

use App\Jobs\NotifyMembers;
use App\Models\Blog;
use App\Models\User;
use App\Notifications\BlogCommentPosted;
use App\Support\Spam\CommentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

        $captchaToken = 'comment-captcha';

        $response = $this
            ->withSession([CommentGuard::SESSION_TOKEN_KEY => $captchaToken])
            ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), [
                'body' => 'First!',
                'captcha_token' => $captchaToken,
                'honeypot' => '',
            ]);

        $response->assertCreated();

        $blog->refresh();
        $comment = $blog->comments()->latest()->first();

        $this->assertNotNull($comment);

        Notification::assertSentTo(
            $subscriber,
            BlogCommentPosted::class,
            function (BlogCommentPosted $notification) use ($blog, $comment, $commentAuthor, $subscriber) {
                $data = $notification->toArray($subscriber);

                $expectedTitle = 'New reply on "'.$blog->title.'"';
                $expectedExcerptPrefix = $commentAuthor->nickname.' replied:';

                return $data['blog_id'] === $blog->id
                    && $data['comment_id'] === $comment->id
                    && $data['comment_author_id'] === $commentAuthor->id
                    && $data['comment_author_nickname'] === $commentAuthor->nickname
                    && $data['title'] === $expectedTitle
                    && $data['thread_title'] === $expectedTitle
                    && str_starts_with($data['excerpt'], $expectedExcerptPrefix)
                    && $data['url'] === route('blogs.view', ['slug' => $blog->slug]).'#comment-'.$comment->id;
            }
        );

        Notification::assertSentTo(
            $anotherSubscriber,
            BlogCommentPosted::class,
            function (BlogCommentPosted $notification) use ($blog, $comment, $commentAuthor, $anotherSubscriber) {
                $data = $notification->toArray($anotherSubscriber);

                $expectedTitle = 'New reply on "'.$blog->title.'"';
                $expectedExcerptPrefix = $commentAuthor->nickname.' replied:';

                return $data['blog_id'] === $blog->id
                    && $data['comment_id'] === $comment->id
                    && $data['comment_author_id'] === $commentAuthor->id
                    && $data['comment_author_nickname'] === $commentAuthor->nickname
                    && $data['title'] === $expectedTitle
                    && $data['thread_title'] === $expectedTitle
                    && str_starts_with($data['excerpt'], $expectedExcerptPrefix)
                    && $data['url'] === route('blogs.view', ['slug' => $blog->slug]).'#comment-'.$comment->id;
            }
        );

        Notification::assertNotSentTo($commentAuthor, BlogCommentPosted::class);
    }

    public function test_the_request_only_picks_who_is_told_and_the_queue_does_the_telling(): void
    {
        Queue::fake();

        $blog = Blog::factory()->published()->create();
        $subscribers = User::factory()->count(2)->create();
        $commentAuthor = User::factory()->create();

        // The post's author follows the comments too, and so does the one commenting: neither is told twice or told of their own comment.
        $blog->commentSubscribers()->attach([...$subscribers->pluck('id')->all(), $blog->user_id, $commentAuthor->id]);

        $this->actingAs($commentAuthor);

        $this->withSession([CommentGuard::SESSION_TOKEN_KEY => 'comment-captcha'])
            ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), [
                'body' => 'First!',
                'captcha_token' => 'comment-captcha',
                'honeypot' => '',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('notifications', 0);

        Queue::assertPushed(NotifyMembers::class, 1);
        Queue::assertPushed(NotifyMembers::class, function (NotifyMembers $job) use ($blog, $subscribers) {
            $expected = [...$subscribers->pluck('id')->all(), $blog->user_id];
            sort($expected);
            $told = $job->recipientIds;
            sort($told);

            return $job->category === 'blogs'
                && $job->notification instanceof BlogCommentPosted
                && $told === $expected;
        });
    }

    public function test_a_comment_on_a_post_with_many_followers_queues_short_jobs(): void
    {
        Queue::fake();

        $blog = Blog::factory()->published()->create();
        $blog->commentSubscribers()->attach(User::factory()->count(NotifyMembers::CHUNK + 5)->create()->pluck('id')->all());

        $this->actingAs(User::factory()->create());

        $this->withSession([CommentGuard::SESSION_TOKEN_KEY => 'comment-captcha'])
            ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), [
                'body' => 'First!',
                'captcha_token' => 'comment-captcha',
                'honeypot' => '',
            ])
            ->assertCreated();

        // 105 followers and the post's author.
        $sizes = Queue::pushed(NotifyMembers::class)->map(fn (NotifyMembers $job) => count($job->recipientIds))->all();

        $this->assertSame([NotifyMembers::CHUNK, 6], $sizes);
    }

    public function test_the_author_of_a_post_is_not_told_of_their_own_comment(): void
    {
        $blog = Blog::factory()->published()->create();
        $subscriber = User::factory()->create();
        $blog->commentSubscribers()->attach($subscriber);

        $this->assertSame([$subscriber->id], $blog->commentRecipientIds($blog->user));
        $this->assertSame([$subscriber->id, $blog->user_id], $blog->commentRecipientIds(User::factory()->create()));
    }
}
