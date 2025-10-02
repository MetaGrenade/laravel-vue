<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\TicketOpened;
use App\Notifications\TicketReplied;
use App\Notifications\TicketStatusUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.reply', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.edit', 'guard_name' => 'web']);
    }

    private function createSupportAgent(array $permissions = []): User
    {
        $agent = User::factory()->create(['email_verified_at' => now()]);
        $agent->assignRole('moderator');

        foreach ($permissions as $permission) {
            $agent->givePermissionTo($permission);
        }

        return $agent;
    }

    public function test_it_notifies_the_owner_when_a_ticket_is_opened(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('support.tickets.store'), [
            'subject' => 'Need help with my account',
            'body' => 'I am unable to access certain features and need assistance.',
            'priority' => 'high',
        ]);

        $response->assertRedirect(route('support'));

        $ticket = SupportTicket::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($ticket);

        $message = $ticket->messages()->latest('id')->first();
        $this->assertNotNull($message);

        Notification::assertSentTo($user, TicketOpened::class, function (TicketOpened $notification, array $channels) use ($user, $ticket, $message) {
            $this->assertSame(['mail', 'database'], $channels);

            $data = $notification->toArray($user);

            $this->assertSame($ticket->id, $data['ticket_id']);
            $this->assertSame('owner', $data['audience']);
            $this->assertSame($message->id, $data['message_id']);

            $expectedUrl = route('support.tickets.show', $ticket) . '#message-' . $message->id;
            $this->assertSame($expectedUrl, $data['url']);

            $mailMessage = $notification->toMail($user);
            $this->assertSame($expectedUrl, $mailMessage->actionUrl);

            return true;
        });
    }

    public function test_it_notifies_owner_and_assigned_agent_when_a_reply_is_stored(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = $this->createSupportAgent(['support.acp.view']);

        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'subject' => 'Deployment issue',
            'body' => 'Production deploy failed last night.',
            'priority' => 'medium',
            'assigned_to' => $agent->id,
        ]);

        $ticket->messages()->create([
            'user_id' => $owner->id,
            'body' => 'Initial description of the deployment failure.',
        ]);

        $this->actingAs($owner);

        $response = $this->post(route('support.tickets.messages.store', $ticket), [
            'body' => 'Here is some additional context about the failure logs.',
        ]);

        $response->assertRedirect(route('support.tickets.show', $ticket));

        $ticket->refresh();
        $message = $ticket->messages()->latest('id')->first();
        $this->assertNotNull($message);

        Notification::assertSentToTimes($owner, TicketReplied::class, 1);
        Notification::assertSentToTimes($agent, TicketReplied::class, 1);

        Notification::assertSentTo($owner, TicketReplied::class, function (TicketReplied $notification, array $channels) use ($owner, $ticket, $message) {
            $this->assertSame(['mail', 'database'], $channels);

            $data = $notification->toArray($owner);

            $this->assertSame($ticket->id, $data['ticket_id']);
            $this->assertSame('owner', $data['audience']);
            $this->assertSame($message->id, $data['message_id']);

            $expectedUrl = route('support.tickets.show', $ticket) . '#message-' . $message->id;
            $this->assertSame($expectedUrl, $data['url']);

            return true;
        });

        Notification::assertSentTo($agent, TicketReplied::class, function (TicketReplied $notification, array $channels) use ($agent, $ticket, $message) {
            $this->assertSame(['mail', 'database'], $channels);

            $data = $notification->toArray($agent);

            $this->assertSame($ticket->id, $data['ticket_id']);
            $this->assertSame('agent', $data['audience']);
            $this->assertSame($message->id, $data['message_id']);

            $expectedUrl = route('acp.support.tickets.show', ['ticket' => $ticket->id]) . '#message-' . $message->id;
            $this->assertSame($expectedUrl, $data['url']);

            $mailMessage = $notification->toMail($agent);
            $this->assertSame($expectedUrl, $mailMessage->actionUrl);

            return true;
        });
    }

    public function test_it_notifies_owner_when_an_agent_replies_from_admin_panel(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = $this->createSupportAgent(['support.acp.reply', 'support.acp.view']);

        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'subject' => 'Database connection issue',
            'body' => 'The staging database is not responding.',
            'priority' => 'high',
            'assigned_to' => $agent->id,
        ]);

        $ticket->messages()->create([
            'user_id' => $owner->id,
            'body' => 'Additional logs attached for review.',
        ]);

        $this->actingAs($agent);

        $response = $this->post(route('acp.support.tickets.messages.store', $ticket), [
            'body' => 'Thanks for the report! We are investigating now.',
        ]);

        $response->assertRedirect(route('acp.support.tickets.show', $ticket));

        $ticket->refresh();
        $message = $ticket->messages()->latest('id')->first();
        $this->assertNotNull($message);

        Notification::assertSentToTimes($owner, TicketReplied::class, 1);
        Notification::assertSentToTimes($agent, TicketReplied::class, 1);

        Notification::assertSentTo($owner, TicketReplied::class, function (TicketReplied $notification, array $channels) use ($owner, $ticket, $message) {
            $this->assertSame(['mail', 'database'], $channels);

            $data = $notification->toArray($owner);

            $this->assertSame($ticket->id, $data['ticket_id']);
            $this->assertSame('owner', $data['audience']);
            $this->assertSame($message->id, $data['message_id']);

            $expectedUrl = route('support.tickets.show', $ticket) . '#message-' . $message->id;
            $this->assertSame($expectedUrl, $data['url']);

            return true;
        });

        Notification::assertSentTo($agent, TicketReplied::class, function (TicketReplied $notification, array $channels) use ($agent, $ticket, $message) {
            $this->assertSame(['mail', 'database'], $channels);

            $data = $notification->toArray($agent);

            $this->assertSame($ticket->id, $data['ticket_id']);
            $this->assertSame('agent', $data['audience']);
            $this->assertSame($message->id, $data['message_id']);

            $expectedUrl = route('acp.support.tickets.show', ['ticket' => $ticket->id]) . '#message-' . $message->id;
            $this->assertSame($expectedUrl, $data['url']);

            return true;
        });
    }

    public function test_it_notifies_owner_and_agent_when_status_is_updated_from_admin_panel(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = $this->createSupportAgent(['support.acp.edit', 'support.acp.view']);

        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'subject' => 'Login issue',
            'body' => 'Cannot login after password reset.',
            'priority' => 'medium',
            'status' => 'pending',
            'assigned_to' => $agent->id,
        ]);

        $this->from(route('acp.support.tickets.show', $ticket))
            ->actingAs($agent)
            ->put(route('acp.support.tickets.status', $ticket), [
                'status' => 'open',
            ])
            ->assertRedirect(route('acp.support.tickets.show', $ticket));

        $ticket->refresh();

        Notification::assertSentToTimes($owner, TicketStatusUpdated::class, 1);
        Notification::assertSentToTimes($agent, TicketStatusUpdated::class, 1);

        Notification::assertSentTo($owner, TicketStatusUpdated::class, function (TicketStatusUpdated $notification, array $channels) use ($owner, $ticket) {
            $this->assertSame(['mail', 'database'], $channels);

            $data = $notification->toArray($owner);

            $this->assertSame($ticket->id, $data['ticket_id']);
            $this->assertSame('owner', $data['audience']);
            $this->assertSame('pending', $data['previous_status']);
            $this->assertSame('open', $data['status']);

            $expectedUrl = route('support.tickets.show', $ticket);
            $this->assertSame($expectedUrl, $data['url']);

            $mailMessage = $notification->toMail($owner);
            $this->assertSame($expectedUrl, $mailMessage->actionUrl);

            return true;
        });

        Notification::assertSentTo($agent, TicketStatusUpdated::class, function (TicketStatusUpdated $notification, array $channels) use ($agent, $ticket) {
            $this->assertSame(['mail', 'database'], $channels);

            $data = $notification->toArray($agent);

            $this->assertSame($ticket->id, $data['ticket_id']);
            $this->assertSame('agent', $data['audience']);
            $this->assertSame('pending', $data['previous_status']);
            $this->assertSame('open', $data['status']);

            $expectedUrl = route('acp.support.tickets.show', ['ticket' => $ticket->id]);
            $this->assertSame($expectedUrl, $data['url']);

            $mailMessage = $notification->toMail($agent);
            $this->assertSame($expectedUrl, $mailMessage->actionUrl);

            return true;
        });
    }
}

