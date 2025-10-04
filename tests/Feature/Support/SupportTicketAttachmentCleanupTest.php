<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketAttachmentCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_message_removes_attachment_from_storage_and_database(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Login issue',
            'body' => 'Cannot log in to my account.',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $message = $ticket->messages()->create([
            'user_id' => $user->id,
            'body' => 'Here are additional details.',
        ]);

        $path = "support-attachments/{$ticket->id}/details.txt";
        Storage::disk('public')->put($path, 'stack trace contents');

        $attachment = $message->attachments()->create([
            'disk' => 'public',
            'path' => $path,
            'name' => 'details.txt',
            'mime_type' => 'text/plain',
            'size' => 128,
        ]);

        Storage::disk('public')->assertExists($path);

        $message->delete();

        Storage::disk('public')->assertMissing($path);

        $this->assertDatabaseMissing('support_ticket_message_attachments', [
            'id' => $attachment->id,
        ]);
    }

    public function test_deleting_ticket_removes_nested_attachments_from_storage_and_database(): void
    {
        Storage::fake('public');

        $requester = User::factory()->create();
        $agent = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'API outage',
            'body' => 'Our API endpoint is down.',
            'status' => 'pending',
            'priority' => 'high',
        ]);

        $customerMessage = $ticket->messages()->create([
            'user_id' => $requester->id,
            'body' => 'Received error 500 responses.',
        ]);

        $agentMessage = $ticket->messages()->create([
            'user_id' => $agent->id,
            'body' => 'We are investigating.',
        ]);

        $customerAttachmentPath = "support-attachments/{$ticket->id}/error.log";
        $agentAttachmentPath = "support-attachments/{$ticket->id}/resolution.pdf";

        Storage::disk('public')->put($customerAttachmentPath, 'error output');
        Storage::disk('public')->put($agentAttachmentPath, 'resolution steps');

        $customerAttachment = $customerMessage->attachments()->create([
            'disk' => 'public',
            'path' => $customerAttachmentPath,
            'name' => 'error.log',
            'mime_type' => 'text/plain',
            'size' => 64,
        ]);

        $agentAttachment = $agentMessage->attachments()->create([
            'disk' => 'public',
            'path' => $agentAttachmentPath,
            'name' => 'resolution.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
        ]);

        Storage::disk('public')->assertExists($customerAttachmentPath);
        Storage::disk('public')->assertExists($agentAttachmentPath);

        $ticket->delete();

        Storage::disk('public')->assertMissing($customerAttachmentPath);
        Storage::disk('public')->assertMissing($agentAttachmentPath);

        $this->assertDatabaseMissing('support_ticket_message_attachments', [
            'id' => $customerAttachment->id,
        ]);

        $this->assertDatabaseMissing('support_ticket_message_attachments', [
            'id' => $agentAttachment->id,
        ]);

        $this->assertDatabaseMissing('support_ticket_messages', [
            'id' => $customerMessage->id,
        ]);

        $this->assertDatabaseMissing('support_ticket_messages', [
            'id' => $agentMessage->id,
        ]);
    }
}
