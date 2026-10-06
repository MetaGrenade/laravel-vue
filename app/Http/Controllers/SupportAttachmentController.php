<?php

namespace App\Http\Controllers;

use App\Models\SupportTicketMessageAttachment;
use App\Support\SupportAttachmentStorage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves support-ticket attachments to the people allowed to see the ticket.
 *
 * Used by both the web app (session) and the API (Sanctum token); the route
 * middleware decides how the caller authenticates, the policy decides access.
 */
class SupportAttachmentController extends Controller
{
    public function __construct(private readonly SupportAttachmentStorage $storage) {}

    public function download(SupportTicketMessageAttachment $attachment): StreamedResponse
    {
        Gate::authorize('download', $attachment);

        $disk = Storage::disk((string) $attachment->disk);

        abort_unless($attachment->path && $disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->name, [
            'Content-Type' => $this->storage->downloadType($attachment),
            // Never let a browser sniff a different type or render the file.
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
