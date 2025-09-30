<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_close_own_ticket(): void
    {
        $user = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Issue resolved',
            'body' => 'No longer need assistance.',
            'status' => 'open',
            'priority' => 'low',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('support.tickets.status', $ticket), [
                'status' => 'closed',
            ]);

        $response->assertNoContent();
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_user_cannot_close_ticket_they_do_not_own(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $otherUser->id,
            'subject' => 'Need help',
            'body' => 'Please assist.',
            'status' => 'pending',
            'priority' => 'medium',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('support.tickets.status', $ticket), [
                'status' => 'closed',
            ]);

        $response->assertForbidden();
        $this->assertSame('pending', $ticket->fresh()->status);
    }
}
