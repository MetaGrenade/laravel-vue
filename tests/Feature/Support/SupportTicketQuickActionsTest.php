<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_admin_can_assign_ticket_to_an_agent(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $agent = User::factory()->create();
        $requestor = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requestor->id,
            'subject' => 'Need help with onboarding',
            'body' => 'Customer needs additional onboarding information.',
            'status' => 'open',
            'priority' => 'low',
        ]);

        $response = $this
            ->actingAs($admin)
            ->from(route('acp.support.index'))
            ->put(route('acp.support.tickets.assign', $ticket), [
                'assigned_to' => $agent->id,
            ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket assigned to agent.');

        $this->assertSame($agent->id, $ticket->fresh()->assigned_to);
    }

    public function test_admin_can_update_ticket_priority(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $requestor = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requestor->id,
            'subject' => 'Priority adjustment needed',
            'body' => 'Ticket priority should be increased.',
            'status' => 'pending',
            'priority' => 'medium',
        ]);

        $response = $this
            ->actingAs($admin)
            ->from(route('acp.support.index'))
            ->put(route('acp.support.tickets.priority', $ticket), [
                'priority' => 'high',
            ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket priority updated.');

        $this->assertSame('high', $ticket->fresh()->priority);
    }

    public function test_admin_can_lower_ticket_priority(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $requestor = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requestor->id,
            'subject' => 'Review ticket priority',
            'body' => 'Ticket priority should be decreased.',
            'status' => 'open',
            'priority' => 'high',
        ]);

        $response = $this
            ->actingAs($admin)
            ->from(route('acp.support.index'))
            ->put(route('acp.support.tickets.priority', $ticket), [
                'priority' => 'low',
            ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket priority updated.');

        $this->assertSame('low', $ticket->fresh()->priority);
    }

    public function test_admin_can_toggle_ticket_status(): void
    {
        Carbon::setTestNow('2025-02-15 12:00:00');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $requestor = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requestor->id,
            'subject' => 'Close this ticket',
            'body' => 'Issue has been resolved.',
            'status' => 'open',
            'priority' => 'low',
        ]);

        $response = $this
            ->actingAs($admin)
            ->from(route('acp.support.index'))
            ->put(route('acp.support.tickets.status', $ticket), [
                'status' => 'closed',
            ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket closed.');

        $freshTicket = $ticket->fresh();

        $this->assertSame('closed', $freshTicket->status);
        $this->assertNotNull($freshTicket->resolved_at);
        $this->assertTrue($freshTicket->resolved_at->equalTo(Carbon::now()));
        $this->assertSame($admin->id, $freshTicket->resolved_by);
        $this->assertNull($freshTicket->customer_satisfaction_rating);

        Carbon::setTestNow();
    }

    public function test_admin_reopening_ticket_clears_resolution_metadata(): void
    {
        Carbon::setTestNow('2025-02-15 13:00:00');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $requestor = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requestor->id,
            'subject' => 'Follow up on closed issue',
            'body' => 'Need to reopen for additional context.',
            'status' => 'closed',
            'priority' => 'medium',
            'resolved_at' => Carbon::now()->subDay(),
            'resolved_by' => $admin->id,
            'customer_satisfaction_rating' => 4,
        ]);

        $response = $this
            ->actingAs($admin)
            ->from(route('acp.support.index'))
            ->put(route('acp.support.tickets.status', $ticket), [
                'status' => 'open',
            ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket opened.');

        $freshTicket = $ticket->fresh();

        $this->assertSame('open', $freshTicket->status);
        $this->assertNull($freshTicket->resolved_at);
        $this->assertNull($freshTicket->resolved_by);
        $this->assertNull($freshTicket->customer_satisfaction_rating);

        Carbon::setTestNow();
    }
}
