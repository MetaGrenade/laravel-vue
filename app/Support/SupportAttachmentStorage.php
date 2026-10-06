<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stores support-ticket attachments on a private disk and builds the
 * authorised download links for them.
 *
 * Attachments must never be written to a disk that is served directly by the
 * web server: ticket files routinely contain logs, screenshots and personal
 * data, so every download goes through SupportAttachmentController.
 */
class SupportAttachmentStorage
{
    public const MOVED = 'moved';

    public const SKIPPED = 'skipped';

    public const MISSING = 'missing';

    public const FAILED = 'failed';

    /**
     * Media types that are safe to report back on download. Anything else is
     * sent as an opaque binary so a stored (possibly legacy, client-supplied)
     * type can never make a browser render the file.
     *
     * @var list<string>
     */
    private const DOWNLOADABLE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/json',
        'application/x-ndjson',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function disk(): string
    {
        return (string) config('support.attachments.disk', 'local');
    }

    /**
     * Store every uploaded file on the message. Accepts what
     * `$request->file('attachments')` returns: a file, an array of files or null.
     */
    public function attach(SupportTicketMessage $message, SupportTicket $ticket, mixed $files): void
    {
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (! is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $message->attachments()->create($this->store($file, $ticket));
        }
    }

    /**
     * @return array{disk: string, path: string, name: string, mime_type: string, size: int}
     */
    public function store(UploadedFile $file, SupportTicket $ticket): array
    {
        $disk = $this->disk();
        $directory = trim((string) config('support.attachments.directory', 'support-attachments'), '/')."/{$ticket->id}";

        // The stored name is generated (hash plus an extension guessed from the
        // file's content), never taken from the client.
        $path = $file->store($directory, ['disk' => $disk, 'visibility' => 'private']);

        return [
            'disk' => $disk,
            'path' => (string) $path,
            'name' => $this->displayName($file),
            // Detected from the file's content, not the client-declared header.
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) ($file->getSize() ?: 0),
        ];
    }

    /**
     * A safe name to show to users and to send as the download filename.
     */
    public function displayName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        // Control characters and anything that could break a header.
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);
        $name = trim($name, " .\t");
        $name = mb_substr($name, 0, 150);

        return $name !== '' ? $name : $file->hashName();
    }

    /**
     * Media type to send when downloading the attachment.
     */
    public function downloadType(SupportTicketMessageAttachment $attachment): string
    {
        $type = strtolower((string) $attachment->mime_type);

        return in_array($type, self::DOWNLOADABLE_TYPES, true) ? $type : 'application/octet-stream';
    }

    /**
     * Authorised download URL. The API variant is absolute so clients can follow it.
     */
    public function downloadUrl(SupportTicketMessageAttachment $attachment, bool $api = false): string
    {
        return $api
            ? route('api.v1.support.attachments.download', ['attachment' => $attachment->id])
            : route('support.attachments.download', ['attachment' => $attachment->id], false);
    }

    /**
     * Move an attachment from the disk it was originally stored on (the public
     * disk, before this was fixed) to the configured private disk.
     *
     * The row is only pointed at the private disk once the original has really
     * been removed. If the original cannot be deleted the row keeps naming it,
     * so the attachment stays eligible for the next run and the failure is
     * reported instead of leaving a publicly reachable copy that looks moved.
     *
     * @return self::MOVED|self::SKIPPED|self::MISSING|self::FAILED
     */
    public function privatize(SupportTicketMessageAttachment $attachment): string
    {
        $target = $this->disk();

        if ($attachment->disk === $target) {
            return self::SKIPPED;
        }

        $path = $attachment->path;

        if (! $path) {
            return self::MISSING;
        }

        $source = Storage::disk((string) $attachment->disk);
        $destination = Storage::disk($target);

        try {
            if (! $source->exists($path)) {
                // A previous run may have copied the file and deleted the
                // original but stopped before updating the row; finish that.
                if ($destination->exists($path)) {
                    $attachment->forceFill(['disk' => $target])->save();

                    return self::MOVED;
                }

                return self::MISSING;
            }

            $stream = $source->readStream($path);

            if (! is_resource($stream)) {
                return self::FAILED;
            }

            try {
                $written = $destination->writeStream($path, $stream, ['visibility' => 'private']);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($written === false) {
                return self::FAILED;
            }

            // delete() reports failure by returning false rather than throwing.
            // Leave the row on the original disk in that case so it is retried.
            if (! $source->delete($path)) {
                return self::FAILED;
            }

            $attachment->forceFill(['disk' => $target])->save();

            return self::MOVED;
        } catch (Throwable $exception) {
            report($exception);

            return self::FAILED;
        }
    }
}
