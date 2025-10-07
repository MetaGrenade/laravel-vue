<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\User;
use App\Support\FileScanning\FileScanner;
use App\Support\FileScanning\FileScannerFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketAttachmentScanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_attachment_is_uploaded_when_scan_passes(): void
    {
        $quarantineDisk = config('filescanner.quarantine_disk', 'local');
        $quarantinePath = trim((string) config('filescanner.quarantine_path', 'quarantine/support-attachments'), '/');

        Storage::fake('public');
        Storage::fake($quarantineDisk);

        Notification::fake();

        $user = User::factory()->create();

        $scanner = (new FileScannerFake())->pushClean('File is clean');
        $this->app->instance(FileScanner::class, $scanner);

        $response = $this->actingAs($user)->post(route('support.tickets.store'), [
            'subject' => 'Need assistance with billing',
            'body' => 'Please help me with my invoice.',
            'priority' => 'medium',
            'attachments' => [
                UploadedFile::fake()->create('diagnostics.txt', 10, 'text/plain'),
            ],
        ]);

        $response->assertRedirect(route('support'));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('blocked_attachments');
        $response->assertSessionDoesntHaveErrors();

        $ticket = SupportTicket::first();
        $this->assertNotNull($ticket);

        $message = $ticket->messages()->first();
        $this->assertNotNull($message);

        $attachment = $message->attachments()->first();
        $this->assertNotNull($attachment);
        $this->assertSame('diagnostics.txt', $attachment->name);

        Storage::disk('public')->assertExists($attachment->path);
        $this->assertSame(1, $message->attachments()->count());

        $quarantineRoot = $quarantinePath !== '' ? $quarantinePath : null;
        $quarantineFiles = $quarantineRoot !== null
            ? Storage::disk($quarantineDisk)->allFiles($quarantineRoot)
            : Storage::disk($quarantineDisk)->allFiles();

        $this->assertCount(0, $quarantineFiles);
    }

    public function test_reply_attachment_is_quarantined_and_reported_when_scan_fails(): void
    {
        $quarantineDisk = config('filescanner.quarantine_disk', 'local');
        $quarantinePath = trim((string) config('filescanner.quarantine_path', 'quarantine/support-attachments'), '/');

        Storage::fake('public');
        Storage::fake($quarantineDisk);

        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->create([
            'user_id' => $user->id,
        ]);

        Notification::fake();

        $scanner = (new FileScannerFake())->pushBlocked('Virus detected');
        $this->app->instance(FileScanner::class, $scanner);

        $response = $this->actingAs($user)->post(route('support.tickets.messages.store', $ticket), [
            'body' => 'Additional information attached.',
            'attachments' => [
                UploadedFile::fake()->create('payload.zip', 10, 'application/zip'),
            ],
        ]);

        $response->assertRedirect(route('support.tickets.show', $ticket));
        $response->assertSessionHas('success');
        $response->assertSessionHasErrors('attachments');
        $response->assertSessionHas('blocked_attachments', function ($value) {
            return is_array($value)
                && count($value) === 1
                && $value[0]['name'] === 'payload.zip'
                && $value[0]['reason'] === 'Virus detected';
        });

        $ticket->refresh();
        $message = $ticket->messages()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame(0, $message->attachments()->count());

        $this->assertCount(0, Storage::disk('public')->allFiles("support-attachments/{$ticket->id}"));
        $quarantineDirectory = $quarantinePath !== '' ? $quarantinePath.'/'.$ticket->id : (string) $ticket->id;

        $this->assertCount(1, Storage::disk($quarantineDisk)->allFiles($quarantineDirectory));
    }
}
