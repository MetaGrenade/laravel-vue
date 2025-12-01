<?php

namespace Tests\Feature\Api;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenHeaders(User $user): array
    {
        $token = $user->createToken('API Client')->plainTextToken;

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    public function test_user_can_create_and_view_ticket_with_attachments(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $headers = $this->tokenHeaders($user);

        $payload = [
            'subject' => 'API ticket',
            'body' => 'Need help from support.',
            'priority' => 'high',
            'attachments' => [
                UploadedFile::fake()->create('error.log', 2),
            ],
        ];

        $response = $this->withHeaders($headers)
            ->post('/api/v1/support/tickets', $payload);

        $response->assertCreated()
            ->assertJsonPath('subject', 'API ticket')
            ->assertJsonPath('messages.0.body', 'Need help from support.');

        $ticket = SupportTicket::first();
        $this->assertNotNull($ticket);

        $attachment = SupportTicketMessageAttachment::first();
        $this->assertNotNull($attachment);
        Storage::disk('public')->assertExists($attachment->path);

        $this->withHeaders($headers)
            ->getJson('/api/v1/support/tickets/'.$ticket->id)
            ->assertOk()
            ->assertJsonPath('messages.0.attachments.0.name', $attachment->name);
    }

    public function test_user_can_reply_to_ticket_with_attachment(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);
        $headers = $this->tokenHeaders($user);

        $payload = [
            'body' => 'Here is another update.',
            'attachments' => [
                UploadedFile::fake()->image('screenshot.png'),
            ],
        ];

        $response = $this->withHeaders($headers)
            ->post('/api/v1/support/tickets/'.$ticket->id.'/messages', $payload);

        $response->assertCreated()
            ->assertJsonPath('message.body', 'Here is another update.')
            ->assertJsonPath('message.attachments.0.name', 'screenshot.png');

        $attachment = SupportTicketMessageAttachment::latest('id')->first();
        $this->assertNotNull($attachment);
        Storage::disk('public')->assertExists($attachment->path);
    }

    public function test_user_can_close_reopen_and_rate_ticket(): void
    {
        $user = User::factory()->create();
        $headers = $this->tokenHeaders($user);

        $ticket = SupportTicket::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->withHeaders($headers)
            ->patchJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        $ticket->refresh();
        $this->assertEquals('closed', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertEquals($user->id, $ticket->resolved_by);

        $this->withHeaders($headers)
            ->patchJson('/api/v1/support/tickets/'.$ticket->id.'/reopen')
            ->assertOk()
            ->assertJsonPath('status', 'open');

        $ticket->refresh();
        $this->assertEquals('open', $ticket->status);

        $ticket->update([
            'status' => 'closed',
            'resolved_at' => now(),
            'resolved_by' => $user->id,
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/v1/support/tickets/'.$ticket->id.'/rating', ['rating' => 5])
            ->assertOk()
            ->assertJsonPath('customer_satisfaction_rating', 5);

        $this->assertEquals(5, $ticket->fresh()->customer_satisfaction_rating);
    }

    public function test_users_cannot_modify_tickets_they_do_not_own(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'user_id' => $owner->id,
            'status' => 'open',
        ]);

        $headers = $this->tokenHeaders($otherUser);

        $this->withHeaders($headers)
            ->postJson('/api/v1/support/tickets/'.$ticket->id.'/messages', ['body' => 'Attempted reply'])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->patchJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'closed'])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->postJson('/api/v1/support/tickets/'.$ticket->id.'/rating', ['rating' => 4])
            ->assertForbidden();
    }
}
