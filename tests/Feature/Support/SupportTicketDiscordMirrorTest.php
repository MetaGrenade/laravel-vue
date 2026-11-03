<?php

namespace Tests\Feature\Support;

use App\Events\SupportTicketDiscordMirror;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SupportTicketDiscordMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_ticket_creation_dispatches_opened_discord_mirror_once(): void
    {
        Event::fake([SupportTicketDiscordMirror::class]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->post(route('support.tickets.store'), [
            'subject' => 'Need help with billing',
            'body' => 'I was charged twice for the same order.',
            'priority' => 'medium',
        ])->assertRedirect(route('support'));

        $ticket = SupportTicket::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($ticket);

        Event::assertDispatchedTimes(SupportTicketDiscordMirror::class, 1);
        Event::assertDispatched(
            SupportTicketDiscordMirror::class,
            fn (SupportTicketDiscordMirror $event) => $event->kind === 'opened'
                && $event->ticket->is($ticket)
                && $event->message !== null
                && $event->message->body === 'I was charged twice for the same order.',
        );
    }

    public function test_api_ticket_creation_dispatches_opened_discord_mirror_once(): void
    {
        Event::fake([SupportTicketDiscordMirror::class]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('API Client')->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/support/tickets', [
            'subject' => 'API ticket',
            'body' => 'Created through the support API.',
            'priority' => 'low',
        ])->assertCreated();

        $ticket = SupportTicket::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($ticket);

        Event::assertDispatchedTimes(SupportTicketDiscordMirror::class, 1);
        Event::assertDispatched(
            SupportTicketDiscordMirror::class,
            fn (SupportTicketDiscordMirror $event) => $event->kind === 'opened'
                && $event->ticket->is($ticket)
                && $event->message !== null,
        );
    }
}
