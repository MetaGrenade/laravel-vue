<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_person_who_opened_a_ticket_can_open_it_or_reply(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'user_id' => $owner->id,
            'status' => 'open',
        ]);

        $this->actingAs($other)->get(route('support.tickets.show', $ticket))->assertForbidden();
        $this->actingAs($other)->post(route('support.tickets.messages.store', $ticket), [
            'body' => 'This is not my ticket.',
        ])->assertForbidden();

        $this->actingAs($owner)->get(route('support.tickets.show', $ticket))->assertOk();
    }
}
