<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.view', 'guard_name' => 'web']);
    }

    private function createSupportAgent(): User
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');
        $user->givePermissionTo('support.acp.view');

        return $user;
    }

    public function test_support_index_applies_ticket_filters(): void
    {
        $agent = $this->createSupportAgent();
        $requester = User::factory()->create();

        Carbon::setTestNow('2024-02-01 10:00:00');
        $matchingTicket = SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Database outage',
            'body' => 'The production database is unavailable.',
            'status' => 'open',
            'priority' => 'high',
            'assigned_to' => $agent->id,
        ]);

        Carbon::setTestNow('2024-02-05 12:00:00');
        SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Resolved request',
            'body' => 'This one should be filtered out by status.',
            'status' => 'closed',
            'priority' => 'high',
            'assigned_to' => $agent->id,
        ]);

        Carbon::setTestNow('2024-01-15 09:00:00');
        SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Old request',
            'body' => 'Falls outside the date range and has no assignee.',
            'status' => 'open',
            'priority' => 'high',
        ]);

        Carbon::setTestNow();

        $response = $this->actingAs($agent)->get(route('acp.support.index', [
            'status' => 'open',
            'priority' => 'high',
            'assignee' => $agent->id,
            'date_from' => '2024-02-01',
            'date_to' => '2024-02-02',
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/Support')
            ->where('tickets.meta.total', 1)
            ->where('tickets.data', function ($tickets) use ($matchingTicket) {
                $ids = collect($tickets)->pluck('id');

                return $ids->contains($matchingTicket->id) && $ids->count() === 1;
            })
            ->where('ticketFilters.status', 'open')
            ->where('ticketFilters.priority', 'high')
            ->where('ticketFilters.assignee', $agent->id)
            ->where('ticketFilters.date_from', '2024-02-01')
            ->where('ticketFilters.date_to', '2024-02-02')
        );
    }

    public function test_support_index_preserves_filters_across_pagination(): void
    {
        $agent = $this->createSupportAgent();
        $requester = User::factory()->create();

        Carbon::setTestNow('2024-03-01 08:00:00');
        $firstTicket = SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Integration help',
            'body' => 'Need assistance integrating the API.',
            'status' => 'pending',
            'priority' => 'medium',
            'assigned_to' => $agent->id,
        ]);

        Carbon::setTestNow('2024-03-02 08:00:00');
        $secondTicket = SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Integration follow-up',
            'body' => 'Additional questions on integration.',
            'status' => 'pending',
            'priority' => 'medium',
            'assigned_to' => $agent->id,
        ]);

        Carbon::setTestNow('2024-03-03 09:00:00');
        SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Different priority',
            'body' => 'Should not appear due to priority filter.',
            'status' => 'pending',
            'priority' => 'high',
            'assigned_to' => $agent->id,
        ]);

        Carbon::setTestNow();

        $response = $this->actingAs($agent)->get(route('acp.support.index', [
            'status' => 'pending',
            'priority' => 'medium',
            'assignee' => $agent->id,
            'date_from' => '2024-03-01',
            'per_page' => 1,
            'tickets_page' => 2,
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/Support')
            ->where('tickets.meta.current_page', 2)
            ->where('tickets.meta.total', 2)
            ->where('tickets.data', function ($tickets) use ($secondTicket) {
                $ids = collect($tickets)->pluck('id');

                return $ids->contains($secondTicket->id) && $ids->count() === 1;
            })
            ->where('tickets.links.prev', function ($url) use ($agent) {
                return is_string($url)
                    && str_contains($url, 'status=pending')
                    && str_contains($url, 'priority=medium')
                    && str_contains($url, 'assignee=' . $agent->id)
                    && str_contains($url, 'date_from=2024-03-01');
            })
            ->where('ticketFilters.status', 'pending')
            ->where('ticketFilters.priority', 'medium')
            ->where('ticketFilters.assignee', $agent->id)
            ->where('ticketFilters.date_from', '2024-03-01')
        );

        $this->assertTrue($firstTicket->created_at->lessThan($secondTicket->created_at));
    }
}
