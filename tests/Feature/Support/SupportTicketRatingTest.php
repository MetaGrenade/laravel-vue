<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_owner_can_submit_rating_after_closure(): void
    {
        $user = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Resolved issue',
            'body' => 'Thank you for fixing this.',
            'status' => 'closed',
            'priority' => 'medium',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('support.tickets.rating.store', $ticket), [
                'rating' => 5,
            ]);

        $response->assertRedirect(route('support.tickets.show', $ticket));
        $response->assertSessionHas('success', 'Thanks for sharing your feedback.');

        $this->assertSame(5, $ticket->fresh()->customer_satisfaction_rating);
    }

    public function test_ticket_owner_cannot_rate_before_closure(): void
    {
        $user = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Still open',
            'body' => 'Waiting on resolution.',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('support.tickets.show', $ticket))
            ->post(route('support.tickets.rating.store', $ticket), [
                'rating' => 4,
            ]);

        $response->assertRedirect(route('support.tickets.show', $ticket));
        $response->assertSessionHasErrors('rating');

        $this->assertNull($ticket->fresh()->customer_satisfaction_rating);
    }

    public function test_ticket_owner_cannot_rate_more_than_once(): void
    {
        $user = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Already rated',
            'body' => 'Issue was resolved.',
            'status' => 'closed',
            'priority' => 'medium',
            'customer_satisfaction_rating' => 3,
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('support.tickets.show', $ticket))
            ->post(route('support.tickets.rating.store', $ticket), [
                'rating' => 5,
            ]);

        $response->assertRedirect(route('support.tickets.show', $ticket));
        $response->assertSessionHasErrors('rating');

        $this->assertSame(3, $ticket->fresh()->customer_satisfaction_rating);
    }

    public function test_non_owner_cannot_submit_rating(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $otherUser->id,
            'subject' => 'Closed issue',
            'body' => 'Resolved elsewhere.',
            'status' => 'closed',
            'priority' => 'low',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('support.tickets.rating.store', $ticket), [
                'rating' => 2,
            ]);

        $response->assertForbidden();
        $this->assertNull($ticket->fresh()->customer_satisfaction_rating);
    }
}
