<?php

use App\Models\SupportTicketMessageAttachment;
use App\Support\SupportAttachmentStorage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Support attachments used to be written to the public disk, which made
 * private ticket files reachable by URL. Move any that exist to the private
 * attachment disk. Safe to re-run: files already on the target are skipped,
 * and files that cannot be moved are left in place and reported.
 * `php artisan support:attachments:privatize` repeats the move on demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_ticket_message_attachments')) {
            return;
        }

        $storage = app(SupportAttachmentStorage::class);

        SupportTicketMessageAttachment::query()
            ->where('disk', '!=', $storage->disk())
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use ($storage) {
                foreach ($attachments as $attachment) {
                    $storage->privatize($attachment);
                }
            });
    }

    public function down(): void
    {
        // Intentionally empty: files are never moved back to a public disk.
    }
};
