<?php

use App\Models\SupportTicketMessageAttachment;
use App\Support\SupportAttachmentStorage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
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
        $failed = 0;

        SupportTicketMessageAttachment::query()
            ->where('disk', '!=', $storage->disk())
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use ($storage, &$failed) {
                foreach ($attachments as $attachment) {
                    if ($storage->privatize($attachment) === SupportAttachmentStorage::FAILED) {
                        $failed++;
                    }
                }
            });

        if ($failed > 0) {
            // Do not fail the deployment, but make the problem impossible to miss.
            Log::critical("{$failed} support attachment(s) could not be moved off the public disk and may still be publicly reachable. Fix the cause and run `php artisan support:attachments:privatize`.");
        }
    }

    public function down(): void
    {
        // Intentionally empty: files are never moved back to a public disk.
    }
};
