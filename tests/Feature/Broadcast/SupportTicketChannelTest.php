<?php

namespace Tests\Feature\Broadcast;

use App\Events\SupportTicketUpdated;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\TicketReplied;
use App\Support\SupportTicketNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    public function test_ticket_channel_receives_single_neutral_broadcast(): void
    {
        Event::fake([SupportTicketUpdated::class]);

        $ticket = SupportTicket::factory()->create();

        $agent = User::factory()->create(['email_verified_at' => now()]);
        $agent->givePermissionTo('support.acp.view');

        $ticket->forceFill(['assigned_to' => $agent->id])->save();

        $message = $ticket->messages()->create([
            'user_id' => $ticket->user_id,
            'body' => 'Following up on my ticket.',
        ]);

        $dispatcher = app(SupportTicketNotificationDispatcher::class);

        $dispatcher->dispatch($ticket, function (string $audience) use ($ticket, $message) {
            return (new TicketReplied($ticket, $message))->forAudience($audience);
        });

        Event::assertDispatchedTimes(SupportTicketUpdated::class, 1);

        Event::assertDispatched(SupportTicketUpdated::class, function (SupportTicketUpdated $event) use ($ticket) {
            $payload = $event->broadcastWith();

            return $payload['ticket_id'] === $ticket->id
                && ($payload['event'] ?? null) === 'ticket.message.created'
                && ($payload['is_from_support'] ?? null) === false;
        });
    }
}

