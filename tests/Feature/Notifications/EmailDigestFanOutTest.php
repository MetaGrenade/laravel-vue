<?php

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverEmailDigests;
use App\Jobs\SendEmailDigests;
use App\Models\EmailDigestItem;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Support\Notifications\EmailDigest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use TypeError;

/**
 * The morning digest is many short jobs, not one long one (docs/email-digests.md, "How the morning job runs").
 *
 * It was one job that emailed every member in turn, so the worker's 60 seconds cut it off at some thousands of members, with the lowest
 * ids served and the highest never, every morning; and one member whose delivery threw stopped everyone after them.
 */
class EmailDigestFanOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_morning_job_queues_a_job_for_each_hundred_waiting_members_lowest_id_first(): void
    {
        Queue::fake();
        NotificationFacade::fake();

        $members = User::factory()->count(205)->create();

        // Two members have nothing waiting, and one has several lines: they are left out, and counted once.
        $withoutLines = [$members[2]->id, $members[150]->id];
        $waiting = $members->reject(fn (User $member): bool => in_array($member->id, $withoutLines, true))->values();

        foreach ($waiting as $member) {
            $this->wait($member);
        }

        $this->wait($waiting[10], 'blogs');
        $this->wait($waiting[10], 'messages');

        (new SendEmailDigests)->handle(app(EmailDigest::class));

        $chunks = Queue::pushed(DeliverEmailDigests::class)->map(fn (DeliverEmailDigests $job): array => $job->userIds)->values();

        $this->assertSame([100, 100, 3], $chunks->map(fn (array $ids): int => count($ids))->all());
        $this->assertSame($waiting->pluck('id')->all(), $chunks->flatten()->all());

        // It only queues. Nothing is emailed, and no line is claimed, until the jobs it queued run.
        NotificationFacade::assertNothingSent();
        $this->assertSame($waiting->count() + 2, EmailDigestItem::query()->count());
    }

    public function test_a_morning_with_nothing_waiting_queues_nothing(): void
    {
        Queue::fake();

        User::factory()->count(3)->create();

        (new SendEmailDigests)->handle(app(EmailDigest::class));

        Queue::assertNotPushed(DeliverEmailDigests::class);
    }

    public function test_the_queued_jobs_use_the_default_queue_and_every_member_is_served_when_they_run(): void
    {
        $members = User::factory()->count(5)->create();

        foreach ($members as $member) {
            $this->wait($member);
        }

        Queue::fake();
        (new SendEmailDigests)->handle(app(EmailDigest::class));

        Queue::assertPushed(DeliverEmailDigests::class, fn (DeliverEmailDigests $job): bool => $job->queue === null && $job->connection === null);

        // The jobs it queued, run in turn as a worker would.
        NotificationFacade::fake();
        Queue::pushed(DeliverEmailDigests::class)->each(fn (DeliverEmailDigests $job) => $job->handle(app(EmailDigest::class)));

        foreach ($members as $member) {
            NotificationFacade::assertSentToTimes($member, DailyDigest::class, 1);
        }

        $this->assertSame(0, EmailDigestItem::query()->count());
    }

    /**
     * @return array<string, array{0: Throwable}>
     */
    public static function failures(): array
    {
        return [
            'an exception' => [new RuntimeException('The mailbox is full.')],
            // A bad row can raise a TypeError, which is an Error and not an Exception: it must not stop the others either.
            'an error' => [new TypeError('The mailbox is full.')],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_member_whose_delivery_fails_does_not_stop_the_members_after_them(Throwable $failure): void
    {
        Exceptions::fake();

        [$broken, $second, $third] = User::factory()->count(3)->create()->all();

        foreach ([$broken, $second, $third] as $member) {
            $this->wait($member);
        }

        $fail = true;
        $emailed = [];

        Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$fail, $broken, $failure): void {
            if ($fail && $event->notifiable instanceof User && $event->notifiable->is($broken)) {
                throw $failure;
            }
        });
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$emailed): void {
            if ($event->notifiable instanceof User) {
                $emailed[] = $event->notifiable->id;
            }
        });

        // The member with the lowest id is the one that fails: the job used to stop there, every morning.
        (new SendEmailDigests)->handle(app(EmailDigest::class));

        $this->assertSame([$second->id, $third->id], $emailed);
        Exceptions::assertReportedCount(1);
        $this->assertSame([$failure], Exceptions::reported());

        // Their lines stay, to be sent the next morning, and the others' are gone.
        $this->assertSame([$broken->id], EmailDigestItem::query()->pluck('user_id')->all());

        $fail = false;
        $emailed = [];

        (new SendEmailDigests)->handle(app(EmailDigest::class));

        $this->assertSame([$broken->id], $emailed);
        $this->assertSame(0, EmailDigestItem::query()->count());
    }

    public function test_a_chunk_serves_only_its_own_members_and_reports_how_many_it_emailed(): void
    {
        NotificationFacade::fake();

        [$first, $second, $third, $nobody] = User::factory()->count(4)->create()->all();

        foreach ([$first, $second, $third] as $member) {
            $this->wait($member);
        }

        $sent = app(EmailDigest::class)->deliverTo([$first->id, $nobody->id, $third->id]);

        $this->assertSame(2, $sent);
        NotificationFacade::assertSentToTimes($first, DailyDigest::class, 1);
        NotificationFacade::assertSentToTimes($third, DailyDigest::class, 1);
        NotificationFacade::assertNotSentTo($second, DailyDigest::class);
        $this->assertSame([$second->id], EmailDigestItem::query()->pluck('user_id')->all());
    }

    public function test_a_chunk_the_worker_runs_again_sends_nothing_twice(): void
    {
        NotificationFacade::fake();

        $members = User::factory()->count(3)->create();

        foreach ($members as $member) {
            $this->wait($member);
        }

        $job = new DeliverEmailDigests($members->pluck('id')->all());

        $job->handle(app(EmailDigest::class));
        $this->wait($members[1], 'blogs');
        $job->handle(app(EmailDigest::class));

        NotificationFacade::assertSentToTimes($members[0], DailyDigest::class, 1);
        NotificationFacade::assertSentToTimes($members[1], DailyDigest::class, 2);
        NotificationFacade::assertSentToTimes($members[2], DailyDigest::class, 1);
        $this->assertSame(0, EmailDigestItem::query()->count());
    }

    public function test_the_morning_job_run_twice_sends_each_line_once(): void
    {
        NotificationFacade::fake();

        $members = User::factory()->count(4)->create();

        foreach ($members as $member) {
            $this->wait($member);
        }

        (new SendEmailDigests)->handle(app(EmailDigest::class));
        (new SendEmailDigests)->handle(app(EmailDigest::class));

        foreach ($members as $member) {
            NotificationFacade::assertSentToTimes($member, DailyDigest::class, 1);
        }
    }

    public function test_the_waiting_members_are_read_a_page_at_a_time_in_id_order(): void
    {
        $members = User::factory()->count(6)->create();

        // Members 2 and 5 have nothing waiting; member 3 has three lines and is listed once.
        foreach ([0, 2, 3, 5] as $position) {
            $this->wait($members[$position]);
        }

        $this->wait($members[2], 'blogs');
        $this->wait($members[2], 'messages');

        $digest = app(EmailDigest::class);
        $ids = $members->pluck('id')->all();

        $this->assertSame([$ids[0], $ids[2]], $digest->waitingMemberIds(0, 2));
        $this->assertSame([$ids[3], $ids[5]], $digest->waitingMemberIds($ids[2], 2));
        $this->assertSame([$ids[3]], $digest->waitingMemberIds($ids[2], 1));
        $this->assertSame([$ids[5]], $digest->waitingMemberIds($ids[3], 10));
        $this->assertSame([], $digest->waitingMemberIds($ids[5], 10));
        $this->assertSame([$ids[0], $ids[2], $ids[3], $ids[5]], $digest->waitingMemberIds(0, 10));
    }

    public function test_the_page_of_members_is_put_in_order_by_the_query_and_not_by_luck(): void
    {
        // A database that happens to read an index in order gives the right answer without the ORDER BY, and the walk (everything above
        // the highest id of the last page) depends on it: so it is pinned in the SQL.
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(EmailDigest::class)->waitingMemberIds(0, 10);

        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/order by\s+["`]?user_id["`]?\s+asc/i', $queries[0]);
        $this->assertMatchesRegularExpression('/select distinct/i', $queries[0]);
    }

    private function wait(User $member, string $category = 'forums'): void
    {
        EmailDigestItem::query()->create([
            'user_id' => $member->id,
            'category' => $category,
            'title' => 'Something happened',
            'excerpt' => null,
            'url' => route('forum.index'),
        ]);
    }
}
