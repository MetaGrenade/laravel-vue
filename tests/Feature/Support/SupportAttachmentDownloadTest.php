<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Models\User;
use App\Support\SupportAttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupportAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function attachment(?User $owner = null, string $disk = 'local', string $mime = 'text/plain'): SupportTicketMessageAttachment
    {
        $owner ??= User::factory()->create();
        $ticket = SupportTicket::factory()->create(['user_id' => $owner->id]);
        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $owner->id,
            'body' => 'See attached.',
        ]);

        $path = "support-attachments/{$ticket->id}/secret.txt";
        Storage::disk($disk)->put($path, 'private ticket contents');

        return $message->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'name' => 'secret.txt',
            'mime_type' => $mime,
            'size' => 23,
        ]);
    }

    private function staff(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        return $user;
    }

    #[Test]
    public function the_ticket_owner_can_download_their_attachment(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $attachment = $this->attachment($owner);

        $response = $this->actingAs($owner)->get(route('support.attachments.download', $attachment));

        $response->assertOk()
            ->assertDownload('secret.txt')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function support_staff_can_download_any_ticket_attachment(): void
    {
        Storage::fake('local');
        $attachment = $this->attachment();

        $this->actingAs($this->staff('support.acp.view'))
            ->get(route('support.attachments.download', $attachment))
            ->assertOk();
    }

    #[Test]
    public function other_users_cannot_download_the_attachment(): void
    {
        Storage::fake('local');
        $attachment = $this->attachment();

        $this->actingAs(User::factory()->create())
            ->get(route('support.attachments.download', $attachment))
            ->assertForbidden();
    }

    #[Test]
    public function staff_without_the_support_permission_cannot_download_it(): void
    {
        Storage::fake('local');
        $attachment = $this->attachment();

        $this->actingAs($this->staff('blogs.acp.view'))
            ->get(route('support.attachments.download', $attachment))
            ->assertForbidden();
    }

    #[Test]
    public function guests_are_sent_to_log_in(): void
    {
        Storage::fake('local');
        $attachment = $this->attachment();

        $this->get(route('support.attachments.download', $attachment))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function the_api_requires_a_token(): void
    {
        Storage::fake('local');
        $attachment = $this->attachment();

        $this->getJson(route('api.v1.support.attachments.download', $attachment))->assertUnauthorized();
    }

    #[Test]
    public function the_api_serves_the_download_to_the_ticket_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $attachment = $this->attachment($owner);

        $this->withToken($owner->createToken('client')->plainTextToken)
            ->get(route('api.v1.support.attachments.download', $attachment))
            ->assertOk()
            ->assertDownload('secret.txt');
    }

    #[Test]
    public function the_api_refuses_other_users_tokens(): void
    {
        Storage::fake('local');
        $attachment = $this->attachment();

        $this->withToken(User::factory()->create()->createToken('client')->plainTextToken)
            ->getJson(route('api.v1.support.attachments.download', $attachment))
            ->assertForbidden();
    }

    #[Test]
    public function a_missing_file_returns_not_found(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $attachment = $this->attachment($owner);
        Storage::disk('local')->delete($attachment->path);

        $this->actingAs($owner)
            ->get(route('support.attachments.download', $attachment))
            ->assertNotFound();
    }

    #[Test]
    public function unsafe_stored_media_types_are_downloaded_as_opaque_binary(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $attachment = $this->attachment($owner, mime: 'text/html');

        $this->actingAs($owner)
            ->get(route('support.attachments.download', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');
    }

    #[Test]
    public function new_uploads_are_stored_privately_with_a_sanitised_name_and_detected_type(): void
    {
        Storage::fake('local');
        $ticket = SupportTicket::factory()->create();
        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $ticket->user_id,
            'body' => 'Logs attached.',
        ]);

        $file = UploadedFile::fake()->create("../../evil\"name.pdf\n", 4, 'application/pdf');

        app(SupportAttachmentStorage::class)->attach($message, $ticket, [$file, null]);

        $attachment = $message->attachments()->sole();

        $this->assertSame('local', $attachment->disk);
        $this->assertStringStartsWith("support-attachments/{$ticket->id}/", $attachment->path);
        $this->assertStringNotContainsString('evil', $attachment->path);
        $this->assertSame('evilname.pdf', $attachment->name);
        Storage::disk('local')->assertExists($attachment->path);
    }

    #[Test]
    public function the_privatize_command_moves_legacy_public_attachments(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $legacy = $this->attachment(disk: 'public');
        $current = $this->attachment(disk: 'local');

        $this->artisan('support:attachments:privatize', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('public', $legacy->fresh()->disk);
        Storage::disk('public')->assertExists($legacy->path);

        $this->artisan('support:attachments:privatize')->assertSuccessful();

        $legacy->refresh();
        $this->assertSame('local', $legacy->disk);
        Storage::disk('local')->assertExists($legacy->path);
        Storage::disk('public')->assertMissing($legacy->path);
        $this->assertSame('local', $current->fresh()->disk);

        // Running it again changes nothing.
        $this->artisan('support:attachments:privatize')->assertSuccessful();
        $this->assertSame('local', $legacy->fresh()->disk);
    }

    #[Test]
    public function the_privatize_command_leaves_rows_alone_when_the_file_is_missing(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $legacy = $this->attachment(disk: 'public');
        Storage::disk('public')->delete($legacy->path);

        $this->artisan('support:attachments:privatize')->assertSuccessful();

        $this->assertSame('public', $legacy->fresh()->disk);
    }

    #[Test]
    public function a_failed_delete_of_the_original_keeps_the_attachment_eligible_for_retry(): void
    {
        Storage::fake('local');
        $public = Storage::fake('public');
        $legacy = $this->attachment(disk: 'public');

        // The adapter reports a failed delete by returning false, not by throwing.
        $failing = Mockery::mock($public)->makePartial();
        $failing->shouldReceive('delete')->andReturn(false);
        Storage::set('public', $failing);

        $result = app(SupportAttachmentStorage::class)->privatize($legacy->fresh());

        $this->assertSame(SupportAttachmentStorage::FAILED, $result);
        // Still pointing at the original, so the next run picks it up again...
        $this->assertSame('public', $legacy->fresh()->disk);
        Storage::disk('public')->assertExists($legacy->path);

        // ...and the command reports the problem instead of claiming success.
        $this->artisan('support:attachments:privatize')
            ->expectsOutputToContain('could not be moved')
            ->assertFailed();
        $this->assertSame('public', $legacy->fresh()->disk);

        // Once deletion works again, the retry completes the move.
        Storage::set('public', $public);

        $this->artisan('support:attachments:privatize')->assertSuccessful();

        $legacy->refresh();
        $this->assertSame('local', $legacy->disk);
        Storage::disk('local')->assertExists($legacy->path);
        Storage::disk('public')->assertMissing($legacy->path);
    }

    #[Test]
    public function an_interrupted_move_is_completed_on_the_next_run(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $legacy = $this->attachment(disk: 'public');

        // The copy was made and the original deleted, but the row was never updated.
        Storage::disk('local')->put($legacy->path, 'private ticket contents');
        Storage::disk('public')->delete($legacy->path);

        $result = app(SupportAttachmentStorage::class)->privatize($legacy->fresh());

        $this->assertSame(SupportAttachmentStorage::MOVED, $result);
        $this->assertSame('local', $legacy->fresh()->disk);
    }
}
