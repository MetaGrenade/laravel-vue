<?php

namespace Tests\Feature\Support;

use App\Jobs\HandleSupportTicketMessagePosted;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SupportTicketMessageDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_support_ticket_message_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(
            route('support.tickets.messages.store', $ticket),
            [
                'body' => 'I still need help with this issue.',
            ],
        );

        $response->assertRedirect(route('support.tickets.show', $ticket));
        $response->assertSessionHas('success', 'Your message has been sent.');

        Queue::assertPushed(HandleSupportTicketMessagePosted::class, function ($job) use ($ticket, $user) {
            return $job->ticketId === $ticket->id
                && $job->actorId === $user->id
                && $job->messageId > 0;
        });
    }
}
