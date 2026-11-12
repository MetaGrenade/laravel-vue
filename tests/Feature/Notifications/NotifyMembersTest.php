<?php

namespace Tests\Feature\Notifications;

use App\Jobs\NotifyMembers;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\User;
use App\Notifications\BlogCommentPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Telling many members about one thing from the queue instead of from the request that made the news (docs/notifications.md, "Telling many people").
 */
class NotifyMembersTest extends TestCase
{
    use RefreshDatabase;

    private Blog $blog;

    private BlogComment $comment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::factory()->published()->create();
        $this->comment = BlogComment::factory()->for($this->blog)->create(['status' => BlogComment::STATUS_APPROVED]);
    }

    public function test_a_big_group_becomes_jobs_of_one_hundred_and_each_member_is_in_one_of_them(): void
    {
        Queue::fake();

        NotifyMembers::dispatchInChunks([...range(1, 250), 7, '8'], $this->notification(), 'blogs');

        Queue::assertPushed(NotifyMembers::class, 3);

        $jobs = Queue::pushed(NotifyMembers::class);

        $this->assertSame([100, 100, 50], $jobs->map(fn (NotifyMembers $job) => count($job->recipientIds))->all());
        $this->assertSame(range(1, 250), $jobs->flatMap(fn (NotifyMembers $job) => $job->recipientIds)->all(), 'a repeated id is told once');
        $this->assertTrue($jobs->every(fn (NotifyMembers $job) => $job->afterCommit === true), 'a worker must not look for a post the request has not committed yet');
    }

    public function test_nobody_to_tell_queues_nothing(): void
    {
        Queue::fake();

        NotifyMembers::dispatchInChunks([], $this->notification(), 'blogs');

        Queue::assertNothingPushed();
    }

    public function test_a_job_that_stopped_halfway_is_not_tried_again(): void
    {
        // A second try would tell the members it had already reached a second time.
        $this->assertSame(1, (new NotifyMembers($this->notification(), [], 'blogs'))->tries);
    }

    public function test_each_member_is_told_by_their_own_choices(): void
    {
        Queue::fake();

        $everything = User::factory()->create();
        $emailOnly = User::factory()->create();
        $nothing = User::factory()->create();

        $emailOnly->notificationSettings()->create(['category' => 'blogs', 'channel_mail' => true, 'channel_push' => false, 'channel_database' => false]);
        $nothing->notificationSettings()->create(['category' => 'blogs', 'channel_mail' => false, 'channel_push' => false, 'channel_database' => false]);

        (new NotifyMembers($this->notification(), [$everything->id, $emailOnly->id, $nothing->id], 'blogs'))->handle();

        $this->assertSame([$everything->id], $this->membersWithANotice(), 'the notice in the site is written by the job');

        $queuedFor = Queue::pushed(SendQueuedNotifications::class)
            ->flatMap(fn (SendQueuedNotifications $send) => $send->notifiables->pluck('id'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$everything->id, $emailOnly->id], $queuedFor, 'the email and the browser alert are queued, and only for those who want them');
    }

    public function test_the_settings_of_a_whole_group_are_one_query_and_not_one_each(): void
    {
        Queue::fake();

        $members = User::factory()->count(30)->create();

        $settingsQueries = 0;
        DB::listen(function ($query) use (&$settingsQueries): void {
            if (str_contains($query->sql, 'user_notification_settings')) {
                $settingsQueries++;
            }
        });

        (new NotifyMembers($this->notification(), $members->pluck('id')->all(), 'blogs'))->handle();

        $this->assertCount(30, $this->membersWithANotice());
        $this->assertSame(1, $settingsQueries);
    }

    public function test_one_member_who_cannot_be_told_does_not_leave_the_others_untold(): void
    {
        Queue::fake();
        Exceptions::fake();

        [$first, $unlucky, $last] = User::factory()->count(3)->create()->all();

        $notification = new BlogCommentPostedThatFailsFor($this->blog, $this->comment);
        $notification->failFor = $unlucky->id;

        (new NotifyMembers($notification, [$first->id, $unlucky->id, $last->id], 'blogs'))->handle();

        $this->assertSame([$first->id, $last->id], $this->membersWithANotice());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_member_who_is_gone_by_the_time_the_job_runs_is_skipped(): void
    {
        Queue::fake();
        Exceptions::fake();

        $member = User::factory()->create();

        (new NotifyMembers($this->notification(), [$member->id, 987654], 'blogs'))->handle();

        $this->assertSame([$member->id], $this->membersWithANotice());
        Exceptions::assertNothingReported();
    }

    private function notification(): BlogCommentPosted
    {
        return new BlogCommentPosted($this->blog, $this->comment);
    }

    /**
     * @return list<int>
     */
    private function membersWithANotice(): array
    {
        return DB::table('notifications')->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
    }
}

/**
 * A notification that cannot be written for one member, to see what the job does about it.
 */
class BlogCommentPostedThatFailsFor extends BlogCommentPosted
{
    public int $failFor = 0;

    public function toArray(object $notifiable): array
    {
        if ($notifiable->id === $this->failFor) {
            throw new RuntimeException('This member cannot be told.');
        }

        return parent::toArray($notifiable);
    }
}
