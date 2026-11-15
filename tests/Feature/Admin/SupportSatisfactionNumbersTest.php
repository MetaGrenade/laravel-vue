<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The satisfaction numbers at the top of Admin → Support: the average rating, the ratings by status and the average by month a
 * ticket was resolved. They follow the filters of the list, and they are worked out by the database, not by reading every rated
 * ticket into PHP (a desk with years of tickets would otherwise load all of them on every visit).
 */
class SupportSatisfactionNumbersTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.view', 'guard_name' => 'web']);

        $this->agent = User::factory()->create();
        $this->agent->assignRole('moderator');
        $this->agent->givePermissionTo('support.acp.view');

        $this->requester = User::factory()->create();
    }

    private function ticket(string $subject, string $status, ?int $rating, ?string $resolvedAt, string $createdAt = '2024-01-01 08:00:00'): SupportTicket
    {
        return SupportTicket::forceCreate([
            'user_id' => $this->requester->id,
            'subject' => $subject,
            'body' => "About {$subject}.",
            'status' => $status,
            'priority' => 'low',
            'customer_satisfaction_rating' => $rating,
            'resolved_at' => $resolvedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * Seven tickets: six rated (5, 4, 4, 2, 3, 5), one of them not resolved, one not rated.
     */
    private function desk(): void
    {
        $this->ticket('A late December', 'closed', 5, '2023-12-31 23:30:00', '2023-12-20 08:00:00');
        $this->ticket('B early January', 'closed', 4, '2024-01-01 00:30:00', '2023-12-28 08:00:00');
        $this->ticket('C mid January', 'closed', 4, '2024-01-15 10:00:00', '2024-01-10 08:00:00');
        $this->ticket('D March', 'closed', 2, '2024-03-05 10:00:00', '2024-03-01 08:00:00');
        $this->ticket('E rated but not resolved', 'open', 3, null, '2024-03-10 08:00:00');
        $this->ticket('F not rated', 'pending', null, null, '2024-03-11 08:00:00');
        $this->ticket('G late March', 'closed', 5, '2024-03-20 10:00:00', '2024-03-15 08:00:00');
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function satisfaction(array $query = []): array
    {
        $response = $this->actingAs($this->agent)->get(route('acp.support.index', $query));

        $response->assertOk();

        return $response->viewData('page')['props']['supportStats']['satisfaction'];
    }

    #[Test]
    public function the_average_the_count_the_statuses_and_the_months_are_what_the_ratings_say(): void
    {
        $this->desk();

        $this->actingAs($this->agent)->get(route('acp.support.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/Support')
            // 5 + 4 + 4 + 2 + 3 + 5 = 23 over 6.
            ->where('supportStats.satisfaction.average', 3.83)
            ->where('supportStats.satisfaction.count', 6)
            ->where('supportStats.satisfaction.by_status.closed', ['average' => 4, 'count' => 5])
            ->where('supportStats.satisfaction.by_status.open', ['average' => 3, 'count' => 1])
            ->where('supportStats.satisfaction.by_status.pending', ['average' => null, 'count' => 0])
            // By the month it was resolved, oldest first; the one that was never resolved is in no month.
            ->where('supportStats.satisfaction.by_month', [
                ['month' => '2023-12', 'average' => 5, 'count' => 1],
                ['month' => '2024-01', 'average' => 4, 'count' => 2],
                ['month' => '2024-03', 'average' => 3.5, 'count' => 2],
            ])
        );
    }

    #[Test]
    public function an_average_is_rounded_to_two_places(): void
    {
        // 5 + 4 + 4 over 3 is 4.333..., and 5 + 5 + 4 over 3 is 4.666...
        foreach ([5, 4, 4] as $n => $rating) {
            $this->ticket("May {$n}", 'closed', $rating, '2024-05-10 10:00:00');
        }

        foreach ([5, 5, 4] as $n => $rating) {
            $this->ticket("June {$n}", 'closed', $rating, '2024-06-10 10:00:00');
        }

        $satisfaction = $this->satisfaction();

        // 27 over 6 is 4.5 for the two months together.
        $this->assertSame(4.5, $satisfaction['average']);
        $this->assertSame(4.5, $satisfaction['by_status']['closed']['average']);
        $this->assertSame([
            ['month' => '2024-05', 'average' => 4.33, 'count' => 3],
            ['month' => '2024-06', 'average' => 4.67, 'count' => 3],
        ], $satisfaction['by_month']);
    }

    #[Test]
    public function the_numbers_are_numbers_and_the_months_are_text(): void
    {
        $this->desk();

        $satisfaction = $this->satisfaction();

        $this->assertIsFloat($satisfaction['average']);
        $this->assertIsInt($satisfaction['count']);

        foreach ($satisfaction['by_status'] as $status) {
            $this->assertTrue($status['average'] === null || is_float($status['average']) || is_int($status['average']));
            $this->assertIsInt($status['count']);
        }

        foreach ($satisfaction['by_month'] as $month) {
            $this->assertIsString($month['month']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $month['month']);
            $this->assertIsFloat($month['average']);
            $this->assertIsInt($month['count']);
        }
    }

    #[Test]
    public function a_desk_with_no_ratings_has_no_average_and_no_months(): void
    {
        $this->ticket('Nobody rated this', 'closed', null, '2024-01-15 10:00:00');

        $this->assertEquals([
            'average' => null,
            'count' => 0,
            'by_status' => [
                'open' => ['average' => null, 'count' => 0],
                'pending' => ['average' => null, 'count' => 0],
                'closed' => ['average' => null, 'count' => 0],
            ],
            'by_month' => [],
        ], $this->satisfaction());
    }

    #[Test]
    public function the_numbers_follow_the_status_filter(): void
    {
        $this->desk();

        $satisfaction = $this->satisfaction(['status' => 'closed']);

        // 5 + 4 + 4 + 2 + 5 = 20 over 5.
        $this->assertSame(4.0, $satisfaction['average']);
        $this->assertSame(5, $satisfaction['count']);
        $this->assertSame(['average' => null, 'count' => 0], $satisfaction['by_status']['open']);
        $this->assertSame(['average' => 4.0, 'count' => 5], $satisfaction['by_status']['closed']);
    }

    #[Test]
    public function the_numbers_follow_the_date_filter_on_when_a_ticket_was_opened(): void
    {
        $this->desk();

        // Opened in March: D (2), E (3), F (not rated) and G (5).
        $satisfaction = $this->satisfaction(['date_from' => '2024-03-01', 'date_to' => '2024-03-31']);

        $this->assertSame(3.33, $satisfaction['average']);
        $this->assertSame(3, $satisfaction['count']);
        $this->assertEquals([['month' => '2024-03', 'average' => 3.5, 'count' => 2]], $satisfaction['by_month']);
    }

    #[Test]
    public function the_numbers_follow_the_search(): void
    {
        $this->desk();

        $satisfaction = $this->satisfaction(['tickets_search' => 'January']);

        $this->assertSame(4.0, $satisfaction['average']);
        $this->assertSame(2, $satisfaction['count']);
        $this->assertEquals([['month' => '2024-01', 'average' => 4.0, 'count' => 2]], $satisfaction['by_month']);
    }

    #[Test]
    public function the_numbers_do_not_depend_on_the_page_of_tickets_shown(): void
    {
        $this->desk();

        $whole = $this->satisfaction();

        $this->assertEquals($whole, $this->satisfaction(['per_page' => 2]));
        $this->assertEquals($whole, $this->satisfaction(['per_page' => 2, 'tickets_page' => 2]));
    }

    #[Test]
    public function the_rated_tickets_are_not_read_into_php_to_work_the_numbers_out(): void
    {
        $this->desk();

        foreach (range(1, 30) as $n) {
            $this->ticket("Extra {$n}", 'closed', 1 + $n % 5, sprintf('2024-04-%02d 10:00:00', 1 + $n % 28));
        }

        $read = 0;
        SupportTicket::retrieved(function () use (&$read) {
            $read++;
        });

        $satisfaction = $this->satisfaction(['per_page' => 5]);

        // Thirty-six tickets are rated; the only ones the page reads are the five it shows.
        $this->assertSame(36, $satisfaction['count']);
        $this->assertSame(5, $read);
    }

    #[Test]
    public function a_rated_ticket_is_counted_once_however_many_other_things_belong_to_it(): void
    {
        $this->desk();

        // Replies and an assignee must not multiply a rating (a join would).
        $first = SupportTicket::query()->where('subject', 'A late December')->firstOrFail();
        $first->forceFill(['assigned_to' => $this->agent->id])->save();

        DB::table('support_ticket_messages')->insert(collect(range(1, 3))->map(fn (int $n) => [
            'support_ticket_id' => $first->id,
            'user_id' => $this->agent->id,
            'body' => "Reply {$n}",
            'created_at' => '2024-01-02 09:00:00',
            'updated_at' => '2024-01-02 09:00:00',
        ])->all());

        $satisfaction = $this->satisfaction();

        $this->assertSame(6, $satisfaction['count']);
        $this->assertSame(3.83, $satisfaction['average']);
    }
}
