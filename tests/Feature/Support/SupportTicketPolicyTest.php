<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
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

    public function test_an_administrator_can_close_and_reopen_a_ticket_they_did_not_open(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $owner = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'user_id' => $owner->id,
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->from(route('support.tickets.show', $ticket))
            ->patch(route('support.tickets.status.update', $ticket), [
                'status' => 'closed',
            ])
            ->assertRedirect(route('support.tickets.show', $ticket))
            ->assertSessionHas('success', 'Ticket closed.');

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertSame($admin->id, $ticket->resolved_by);

        $this->actingAs($admin)
            ->from(route('support.tickets.show', $ticket))
            ->patch(route('support.tickets.reopen', $ticket))
            ->assertRedirect(route('support.tickets.show', $ticket))
            ->assertSessionHas('success', 'Ticket reopened. We will take another look.');

        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_someone_else_cannot_close_a_ticket(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'user_id' => $owner->id,
            'status' => 'open',
        ]);

        $this->actingAs($other)
            ->patch(route('support.tickets.status.update', $ticket), [
                'status' => 'closed',
            ])
            ->assertForbidden();

        $this->assertSame('open', $ticket->fresh()->status);
    }
}
