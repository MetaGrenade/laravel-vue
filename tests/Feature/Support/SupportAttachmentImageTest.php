<?php

namespace Tests\Feature\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportAttachmentImageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_picture_is_stored_as_webp_and_a_note_in_the_file_does_not_survive(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $note = 'secret-camera-note';

        $this->actingAs($user)->post(route('support.tickets.store'), [
            'subject' => 'Screenshot',
            'body' => 'The screen looks like this.',
            'priority' => 'medium',
            'attachments' => [$this->pngWithNote('screen.png', $note, 40, 20)],
        ])->assertRedirect(route('support'))->assertSessionHasNoErrors();

        $attachment = SupportTicketMessageAttachment::query()->sole();

        $this->assertSame('screen.webp', $attachment->name);
        $this->assertSame('image/webp', $attachment->mime_type);
        $this->assertStringEndsWith('.webp', (string) $attachment->path);
        $this->assertSame('local', $attachment->disk);

        $stored = Storage::disk('local')->get($attachment->path);

        $this->assertIsString($stored);
        $this->assertStringNotContainsString($note, $stored);

        $info = getimagesizefromstring($stored);

        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertSame(40, $info[0]);
        $this->assertSame(20, $info[1]);
    }

    #[Test]
    public function a_picture_with_too_many_pixels_is_refused_and_no_ticket_is_opened(): void
    {
        Storage::fake('local');
        config(['commerce.images.max_pixels' => 10]);

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('support.tickets.store'), [
            'subject' => 'Too big',
            'body' => 'This picture is enormous.',
            'attachments' => [UploadedFile::fake()->image('big.png', 8, 8)],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function a_reply_with_a_picture_that_is_too_large_does_not_add_a_message(): void
    {
        Storage::fake('local');
        config(['commerce.images.max_pixels' => 10]);

        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->create(['user_id' => $user->id, 'status' => 'open']);

        $this->actingAs($user)->post(route('support.tickets.messages.store', $ticket), [
            'body' => 'Another look.',
            'attachments' => [UploadedFile::fake()->image('big.png', 8, 8)],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, SupportTicketMessage::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function a_pdf_is_kept_as_the_file_that_was_uploaded(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('support.tickets.store'), [
            'subject' => 'A log',
            'body' => 'The PDF is attached.',
            'attachments' => [UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf')],
        ])->assertRedirect(route('support'))->assertSessionHasNoErrors();

        $attachment = SupportTicketMessageAttachment::query()->sole();

        $this->assertSame('notes.pdf', $attachment->name);
        $this->assertNotSame('image/webp', $attachment->mime_type);
        $this->assertStringNotContainsString('.webp', (string) $attachment->path);
    }

    private function pngWithNote(string $name, string $note, int $width, int $height): UploadedFile
    {
        $png = UploadedFile::fake()->image('plain.png', $width, $height)->getContent();
        $chunk = 'tEXt'.'Comment'."\0".$note;
        $text = pack('N', strlen($chunk) - 4).$chunk.pack('N', crc32($chunk));
        $withNote = substr($png, 0, -12).$text.substr($png, -12);

        $this->assertStringContainsString($note, $withNote);

        return UploadedFile::fake()->createWithContent($name, $withNote);
    }
}
