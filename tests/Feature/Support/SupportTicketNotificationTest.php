<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\TicketOpened;
use App\Notifications\TicketReplied;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SupportTicketNotificationTest extends TestCase
{
    use RefreshDatabase;

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

        $owner = User::factory()->create();
        $agent = User::factory()->create();

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
}

