<?php

namespace Tests\Feature;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The six-month chart on a member's dashboard: how many forum replies and support tickets the member made in each of the last six months,
 * this month included.
 *
 * The database counts them by month. It used to read every reply and ticket of those six months as a model, to count them in PHP, on every
 * visit to the dashboard (a member with thousands of replies paid for all of them each time).
 */
class DashboardActivityChartTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private ?ForumThread $thread = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');

        $this->member = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function thread(): ForumThread
    {
        if ($this->thread === null) {
            $category = ForumCategory::query()->create(['slug' => 'general', 'title' => 'General', 'description' => 'General talk', 'position' => 1]);
            $board = ForumBoard::query()->create([
                'forum_category_id' => $category->id,
                'title' => 'Announcements',
                'slug' => 'announcements',
                'description' => 'Announcements',
                'position' => 1,
            ]);

            $this->thread = ForumThread::query()->create([
                'forum_board_id' => $board->id,
                'user_id' => User::factory()->create()->id,
                'title' => 'A thread',
                'slug' => 'a-thread',
                'excerpt' => 'A thread',
                'is_published' => true,
            ]);
        }

        return $this->thread;
    }

    /**
     * Replies written at these times (one row each, with nothing done to the thread or the member, so the time is what is given). Every
     * one was last changed in the month of the test's "now": the chart counts when something was made, not when it was last touched.
     *
     * @param  list<string>  $times
     */
    private function replies(User $author, array $times): void
    {
        DB::table('forum_posts')->insert(array_map(fn (string $at) => [
            'forum_thread_id' => $this->thread()->id,
            'user_id' => $author->id,
            'body' => '<p>A reply</p>',
            'created_at' => $at,
            'updated_at' => Carbon::now()->subHour()->toDateTimeString(),
        ], $times));
    }

    /**
     * @param  list<string>  $times
     */
    private function tickets(User $author, array $times): void
    {
        foreach ($times as $n => $at) {
            SupportTicket::forceCreate([
                'user_id' => $author->id,
                'subject' => "Ticket {$n} at {$at}",
                'body' => 'Something is wrong.',
                'status' => 'open',
                'priority' => 'low',
                'created_at' => $at,
                'updated_at' => Carbon::now()->subHour()->toDateTimeString(),
            ]);
        }
    }

    /**
     * @return list<array{period: string, 'Forum Replies': int, 'Support Tickets': int}>
     */
    private function chart(): array
    {
        $response = $this->actingAs($this->member)->get(route('dashboard'));

        $response->assertOk();

        return $response->viewData('page')['props']['activityChart'];
    }

    #[Test]
    public function each_of_the_last_six_months_counts_the_replies_and_the_tickets_of_the_member(): void
    {
        $other = User::factory()->create();

        $this->replies($this->member, [
            '2026-05-31 23:59:59', // The last second before the window: not in it.
            '2026-06-01 00:00:00', // The first second of it.
            '2026-06-30 23:59:59',
            '2026-08-10 09:00:00',
            '2026-08-10 09:00:00',
            '2026-08-31 23:59:59',
            '2026-11-01 00:00:00',
            '2026-11-15 12:00:00',
            '2026-12-02 10:00:00', // After this month: not one of the six bars.
        ]);
        $this->replies($other, array_fill(0, 4, '2026-08-11 10:00:00'));

        $this->tickets($this->member, ['2026-05-20 08:00:00', '2026-07-05 08:00:00', '2026-07-31 23:59:59', '2026-11-14 08:00:00']);
        $this->tickets($other, array_fill(0, 5, '2026-07-06 08:00:00'));

        $this->assertSame([
            ['period' => 'Jun 2026', 'Forum Replies' => 2, 'Support Tickets' => 0],
            ['period' => 'Jul 2026', 'Forum Replies' => 0, 'Support Tickets' => 2],
            ['period' => 'Aug 2026', 'Forum Replies' => 3, 'Support Tickets' => 0],
            ['period' => 'Sep 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Oct 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Nov 2026', 'Forum Replies' => 2, 'Support Tickets' => 1],
        ], $this->chart());
    }

    #[Test]
    public function a_member_with_nothing_to_show_gets_six_months_of_zeros(): void
    {
        $this->assertSame([
            ['period' => 'Jun 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Jul 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Aug 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Sep 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Oct 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Nov 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
        ], $this->chart());
    }

    #[Test]
    public function the_window_follows_the_calendar_across_a_new_year(): void
    {
        Carbon::setTestNow('2027-02-10 08:30:00');

        $this->replies($this->member, [
            '2026-08-31 23:59:59', // Before the window (September is the first month).
            '2026-09-01 00:00:00',
            '2026-12-31 23:59:59',
            '2027-01-01 00:00:00',
            '2027-01-01 00:00:00',
            '2027-02-09 10:00:00',
        ]);
        $this->tickets($this->member, ['2026-12-15 10:00:00', '2027-01-31 23:59:59']);

        $this->assertSame([
            ['period' => 'Sep 2026', 'Forum Replies' => 1, 'Support Tickets' => 0],
            ['period' => 'Oct 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Nov 2026', 'Forum Replies' => 0, 'Support Tickets' => 0],
            ['period' => 'Dec 2026', 'Forum Replies' => 1, 'Support Tickets' => 1],
            ['period' => 'Jan 2027', 'Forum Replies' => 2, 'Support Tickets' => 1],
            ['period' => 'Feb 2027', 'Forum Replies' => 1, 'Support Tickets' => 0],
        ], $this->chart());
    }

    #[Test]
    public function the_counts_are_whole_numbers(): void
    {
        $this->replies($this->member, ['2026-10-10 10:00:00']);
        $this->tickets($this->member, ['2026-10-11 10:00:00']);

        foreach ($this->chart() as $bar) {
            $this->assertIsString($bar['period']);
            $this->assertIsInt($bar['Forum Replies']);
            $this->assertIsInt($bar['Support Tickets']);
        }
    }

    #[Test]
    public function the_replies_and_tickets_are_not_read_into_php_to_count_them(): void
    {
        $this->replies($this->member, array_map(fn (int $n) => sprintf('2026-09-%02d 10:00:00', 1 + $n % 28), range(1, 40)));
        $this->tickets($this->member, array_map(fn (int $n) => sprintf('2026-10-%02d 10:00:00', 1 + $n % 28), range(1, 30)));

        $replies = 0;
        $tickets = 0;
        ForumPost::retrieved(function () use (&$replies) {
            $replies++;
        });
        SupportTicket::retrieved(function () use (&$tickets) {
            $tickets++;
        });

        $chart = $this->chart();

        $this->assertSame(40, array_sum(array_column($chart, 'Forum Replies')));
        $this->assertSame(30, array_sum(array_column($chart, 'Support Tickets')));

        // What the page does read: the five latest replies and the five latest tickets of the "recent activity" list.
        $this->assertLessThanOrEqual(5, $replies);
        $this->assertLessThanOrEqual(5, $tickets);
    }
}
