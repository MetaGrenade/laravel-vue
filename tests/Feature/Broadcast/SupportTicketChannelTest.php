<?php

namespace Tests\Feature\Broadcast;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupportTicketChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'support.acp.view', 'guard_name' => 'web']);
    }

    public function test_ticket_owner_can_authorize_support_channel(): void
    {
        $ticket = SupportTicket::factory()->create();
        $owner = User::query()->findOrFail($ticket->user_id);

        $this->actingAs($owner);

        $response = $this->post('/broadcasting/auth', [
            'channel_name' => "private-support.tickets.{$ticket->id}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk();
    }

    public function test_support_agent_with_permission_can_authorize_support_channel(): void
    {
        $ticket = SupportTicket::factory()->create();

        $agent = User::factory()->create(['email_verified_at' => now()]);
        $agent->givePermissionTo('support.acp.view');

        $this->actingAs($agent);

        $response = $this->post('/broadcasting/auth', [
            'channel_name' => "private-support.tickets.{$ticket->id}",
            'socket_id' => '9876.5432',
        ]);

        $response->assertOk();
    }

    public function test_user_without_access_cannot_authorize_support_channel(): void
    {
        $ticket = SupportTicket::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->post('/broadcasting/auth', [
            'channel_name' => "private-support.tickets.{$ticket->id}",
            'socket_id' => '1111.2222',
        ]);

        $response->assertForbidden();
    }
}

