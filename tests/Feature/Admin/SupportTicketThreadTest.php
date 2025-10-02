<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketThreadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'support.acp.reply', 'guard_name' => 'web']);
    }

    private function createSupportAgent(array $permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');

        foreach ($permissions as $permissionName) {
            $permission = Permission::findByName($permissionName);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    public function test_support_agent_can_view_ticket_thread(): void
    {
        Storage::fake('public');

        $agent = $this->createSupportAgent(['support.acp.view']);
        $requester = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Cannot access dashboard',
            'body' => 'The dashboard throws a 500 error when I log in.',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $customerMessage = $ticket->messages()->create([
            'user_id' => $requester->id,
            'body' => 'It started failing this morning.',
        ]);

        $path = "support-attachments/{$ticket->id}/error.log";
        Storage::disk('public')->put($path, 'stack trace');

        $customerMessage->attachments()->create([
            'disk' => 'public',
            'path' => $path,
            'name' => 'error.log',
            'mime_type' => 'text/plain',
            'size' => 42,
        ]);

        $ticket->messages()->create([
            'user_id' => $agent->id,
            'body' => 'We are investigating this now.',
        ]);

        $response = $this->actingAs($agent)
            ->get(route('acp.support.tickets.show', $ticket));

        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/SupportTicketView')
            ->where('ticket.id', $ticket->id)
            ->where('ticket.subject', 'Cannot access dashboard')
            ->where('messages', function ($messages) use ($customerMessage) {
                $messages = collect($messages);

                $this->assertCount(2, $messages);

                $first = $messages->first();
                $this->assertSame($customerMessage->id, $first['id']);
                $this->assertSame('It started failing this morning.', $first['body']);
                $this->assertFalse($first['is_from_support']);
                $this->assertCount(1, $first['attachments']);

                return true;
            })
            ->where('canReply', false)
        );
    }

    public function test_support_agent_without_view_permission_cannot_view_ticket(): void
    {
        $agent = $this->createSupportAgent([]);
        $requester = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Permission denied test',
            'body' => 'Ensure authorization works.',
            'status' => 'open',
            'priority' => 'low',
        ]);

        $response = $this->actingAs($agent)
            ->get(route('acp.support.tickets.show', $ticket));

        $response->assertForbidden();
    }

    public function test_support_agent_can_reply_with_attachments(): void
    {
        Storage::fake('public');

        $agent = $this->createSupportAgent(['support.acp.view', 'support.acp.reply']);
        $requester = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Need onboarding help',
            'body' => 'How do I configure SSO?',
            'status' => 'open',
            'priority' => 'high',
        ]);

        $payload = [
            'body' => 'Please review the attached checklist.',
            'attachments' => [UploadedFile::fake()->create('checklist.pdf', 200, 'application/pdf')],
        ];

        $response = $this->actingAs($agent)
            ->from(route('acp.support.tickets.show', $ticket))
            ->post(route('acp.support.tickets.messages.store', $ticket), $payload);

        $response->assertRedirect(route('acp.support.tickets.show', $ticket));
        $response->assertSessionHas('success', 'Reply sent.');

        $message = SupportTicketMessage::query()
            ->where('support_ticket_id', $ticket->id)
            ->where('user_id', $agent->id)
            ->latest()
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('Please review the attached checklist.', $message->body);

        $message->load('attachments');

        $this->assertCount(1, $message->attachments);

        /** @var SupportTicketMessageAttachment $attachment */
        $attachment = $message->attachments->first();

        Storage::disk('public')->assertExists($attachment->path);
        $this->assertSame('checklist.pdf', $attachment->name);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertGreaterThan(0, $attachment->size);
    }

    public function test_support_agent_without_reply_permission_cannot_send_message(): void
    {
        $agent = $this->createSupportAgent(['support.acp.view']);
        $requester = User::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $requester->id,
            'subject' => 'Reply permission test',
            'body' => 'Verify reply guard.',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($agent)
            ->post(route('acp.support.tickets.messages.store', $ticket), [
                'body' => 'Attempting to respond without permission.',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Attempting to respond without permission.',
        ]);
    }
}
