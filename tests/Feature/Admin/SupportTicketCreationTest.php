<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.create', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.edit', 'guard_name' => 'web']);
    }

    private function createSupportAgent(array $permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');

        foreach ($permissions as $permissionName) {
            $permission = Permission::findByName($permissionName);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    public function test_support_agent_self_files_ticket_defaults_to_themselves(): void
    {
        $agent = $this->createSupportAgent(['support.acp.create']);

        $response = $this->actingAs($agent)->post(route('acp.support.tickets.store'), [
            'subject' => 'Community outage',
            'body' => 'Members cannot access the site.',
            'priority' => 'high',
        ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket created.');

        $ticket = SupportTicket::latest()->first();

        $this->assertNotNull($ticket);
        $this->assertSame($agent->id, $ticket->user_id);
        $this->assertSame('Community outage', $ticket->subject);
    }

    public function test_support_agent_can_delegate_ticket_to_selected_user(): void
    {
        $agent = $this->createSupportAgent(['support.acp.create']);
        $requester = User::factory()->create();

        $response = $this->actingAs($agent)->post(route('acp.support.tickets.store'), [
            'subject' => 'Billing question',
            'body' => 'Customer needs an invoice copy.',
            'priority' => 'low',
            'user_id' => $requester->id,
        ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket created.');

        $ticket = SupportTicket::latest()->first();

        $this->assertNotNull($ticket);
        $this->assertSame($requester->id, $ticket->user_id);
        $this->assertNotSame($agent->id, $ticket->user_id);
    }

    public function test_support_agent_can_reassign_requester_when_editing_ticket(): void
    {
        $agent = $this->createSupportAgent(['support.acp.edit']);
        $originalRequester = User::factory()->create();
        $newRequester = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $originalRequester->id,
            'subject' => 'Password reset',
            'body' => 'Unable to reset the password after SSO migration.',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($agent)->put(route('acp.support.tickets.update', $ticket), [
            'user_id' => $newRequester->id,
        ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket updated.');

        $ticket->refresh();

        $this->assertSame($newRequester->id, $ticket->user_id);
    }

    public function test_clearing_requester_defaults_ticket_to_current_agent(): void
    {
        $agent = $this->createSupportAgent(['support.acp.edit']);
        $originalRequester = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $originalRequester->id,
            'subject' => 'Login issue',
            'body' => 'Cannot log in after the recent update.',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($agent)->put(route('acp.support.tickets.update', $ticket), [
            'user_id' => null,
        ]);

        $response->assertRedirect(route('acp.support.index'));
        $response->assertSessionHas('success', 'Ticket updated.');

        $ticket->refresh();

        $this->assertSame($agent->id, $ticket->user_id);
    }
}
