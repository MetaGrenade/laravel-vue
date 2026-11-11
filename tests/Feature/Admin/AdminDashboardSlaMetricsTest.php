<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The support numbers on the admin dashboard: how long open tickets have waited, how fast staff first answer, how long a ticket takes to close.
 * They are worked out by the database, not by reading every ticket (and every message) into PHP: on a site with 20,000 closed tickets the page
 * took over a second and 80 MB. The expected values here are written out by hand, so a change in how they are worked out has to give the same.
 */
class AdminDashboardSlaMetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday at noon, so that "this week" and "the last 8 weeks" are not decided by when the suite happens to run.
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        $this->customer = User::factory()->create();
        $this->agent = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(string $status, Carbon $created, array $attributes = []): SupportTicket
    {
        $ticket = SupportTicket::factory()->create(array_merge(['user_id' => $this->customer->id, 'status' => $status], $attributes));

        // The timestamps are written straight to the table: the model would stamp "now" over them.
        DB::table('support_tickets')->where('id', $ticket->id)->update(['created_at' => $created, 'updated_at' => $created]);

        return $ticket;
    }

    private function message(SupportTicket $ticket, ?User $author, Carbon $at): void
    {
        DB::table('support_ticket_messages')->insert([
            'support_ticket_id' => $ticket->id,
            'user_id' => $author?->id,
            'body' => 'A message that the dashboard has no reason to read.',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sla(): array
    {
        $sla = [];

        $this->actingAs($this->admin)
            ->get(route('acp.dashboard'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$sla) {
                $page->component('acp/Dashboard');
                $sla = $page->toArray()['props']['slaMetrics'];
            });

        return $sla;
    }

    #[Test]
    public function open_tickets_are_counted_by_how_long_they_have_waited_and_the_edges_go_to_the_older_bucket(): void
    {
        $now = Carbon::now();

        // under a day
        $this->ticket('open', $now->copy()->subHours(2));
        $this->ticket('pending', $now->copy()->subHours(23)->subMinutes(59));
        // a day to three days: exactly 24 hours is the first of these, 71 hours 59 minutes the last
        $this->ticket('open', $now->copy()->subHours(24));
        $this->ticket('open', $now->copy()->subHours(71)->subMinutes(59));
        // three to seven days
        $this->ticket('pending', $now->copy()->subHours(72));
        $this->ticket('open', $now->copy()->subHours(167));
        // over seven days
        $this->ticket('open', $now->copy()->subHours(168));
        $this->ticket('pending', $now->copy()->subDays(40));
        // closed tickets are not waiting
        $this->ticket('closed', $now->copy()->subDays(3));

        $this->assertSame([
            'under_1_day' => 2,
            'one_to_three_days' => 2,
            'three_to_seven_days' => 2,
            'over_seven_days' => 2,
        ], $this->sla()['queue_aging']);
    }

    #[Test]
    public function the_average_first_response_counts_the_first_answer_from_somebody_else_in_the_last_eight_weeks(): void
    {
        $now = Carbon::now();

        // Answered after 90 minutes. The customer's own message first, a later answer and a note without an author do not count.
        $quick = $this->ticket('open', $now->copy()->subDays(1));
        $this->message($quick, $this->customer, $now->copy()->subDays(1)->addMinutes(5));
        $this->message($quick, null, $now->copy()->subDays(1)->addMinutes(10));
        $this->message($quick, $this->agent, $now->copy()->subDays(1)->addMinutes(90));
        $this->message($quick, $this->agent, $now->copy()->subDays(1)->addMinutes(600));

        // Answered after 6 hours; the answers are written out of order, so the first by time is the one that counts.
        $slow = $this->ticket('open', $now->copy()->subDays(2));
        $this->message($slow, $this->agent, $now->copy()->subDays(2)->addHours(9));
        $this->message($slow, $this->agent, $now->copy()->subDays(2)->addHours(6));

        // Never answered: only the customer wrote.
        $silent = $this->ticket('open', $now->copy()->subDays(3));
        $this->message($silent, $this->customer, $now->copy()->subDays(3)->addMinutes(1));

        // Too old to count: the range starts at the Monday seven weeks before this week's.
        $old = $this->ticket('open', $now->copy()->subWeeks(12));
        $this->message($old, $this->agent, $now->copy()->subWeeks(12)->addHours(100));

        $response = $this->sla()['response_times'];

        // (90 minutes + 6 hours) / 2 = 3.75 hours, which rounds to 3.8.
        $this->assertSame(3.8, $response['average_first_response_hours']);

        // The trend has eight weeks, oldest first, each named by its Monday; both answers were given in the current week (Monday 9 November).
        $this->assertCount(8, $response['trend']);
        $this->assertSame('Sep 21', $response['trend'][0]['period']);
        $this->assertSame('Nov 9', $response['trend'][7]['period']);
        $this->assertSame(3.8, $response['trend'][7]['Average First Response (hrs)']);
        $this->assertEquals(0.0, $response['trend'][6]['Average First Response (hrs)']);
    }

    #[Test]
    public function a_response_goes_into_the_week_it_was_given_not_the_week_the_ticket_was_opened(): void
    {
        $now = Carbon::now();

        // Opened on Sunday 8 November, answered on Monday 9 November after 2 hours: the answer is in the week of 9 November.
        $ticket = $this->ticket('open', Carbon::parse('2026-11-08 23:00:00'));
        $this->message($ticket, $this->agent, Carbon::parse('2026-11-09 01:00:00'));

        $response = $this->sla()['response_times'];

        $this->assertEquals(2.0, $response['average_first_response_hours']);
        $this->assertSame('Nov 2', $response['trend'][6]['period']);
        $this->assertEquals(0.0, $response['trend'][6]['Average First Response (hrs)']);
        $this->assertEquals(2.0, $response['trend'][7]['Average First Response (hrs)']);
        $this->assertTrue($now->isWednesday());
    }

    #[Test]
    public function the_average_resolution_is_over_every_closed_ticket_that_has_a_resolution_time(): void
    {
        $now = Carbon::now();

        foreach ([[2, 'closed'], [5, 'closed']] as [$hours, $status]) {
            $created = $now->copy()->subDays(400);
            $ticket = $this->ticket($status, $created);
            DB::table('support_tickets')->where('id', $ticket->id)->update(['resolved_at' => $created->copy()->addHours($hours)]);
        }

        // Not counted: closed with no resolution time, and one that is still open although it has one.
        $this->ticket('closed', $now->copy()->subDays(5));
        $reopened = $this->ticket('open', $now->copy()->subDays(5));
        DB::table('support_tickets')->where('id', $reopened->id)->update(['resolved_at' => $now->copy()->subDays(4)]);

        // (2 + 5) / 2 = 3.5 hours.
        $this->assertSame(3.5, $this->sla()['response_times']['average_resolution_hours']);
    }

    #[Test]
    public function with_nothing_to_average_the_dashboard_says_so_instead_of_zero(): void
    {
        $response = $this->sla()['response_times'];

        $this->assertNull($response['average_first_response_hours']);
        $this->assertNull($response['average_resolution_hours']);
        $this->assertSame(['under_1_day' => 0, 'one_to_three_days' => 0, 'three_to_seven_days' => 0, 'over_seven_days' => 0], $this->sla()['queue_aging']);
    }

    #[Test]
    public function the_dashboard_does_not_read_the_text_of_any_message(): void
    {
        $ticket = $this->ticket('open', Carbon::now()->subDays(1));
        $this->message($ticket, $this->agent, Carbon::now()->subHours(20));

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $this->sla();

        $reads = array_filter($statements, fn (string $sql) => str_contains($sql, 'support_ticket_messages'));

        $this->assertNotEmpty($reads, 'The dashboard no longer looks at messages at all; this test has nothing left to check.');

        foreach ($reads as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\bbody\b/i', $sql, 'A statement that reads the text of messages for a page of numbers.');
            $this->assertDoesNotMatchRegularExpression('/select\s+\*|\.\*/i', $sql, 'A statement that reads whole message rows for a page of numbers.');
        }
    }
}
