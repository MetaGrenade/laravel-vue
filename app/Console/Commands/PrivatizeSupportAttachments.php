<?php

namespace App\Console\Commands;

use App\Models\SupportTicketMessageAttachment;
use App\Support\SupportAttachmentStorage;
use Illuminate\Console\Command;

class PrivatizeSupportAttachments extends Command
{
    protected $signature = 'support:attachments:privatize {--dry-run : Report what would move without changing anything}';

    protected $description = 'Move support attachments stored on a publicly served disk to the private attachment disk';

    public function handle(SupportAttachmentStorage $storage): int
    {
        $target = $storage->disk();
        $pending = SupportTicketMessageAttachment::query()->where('disk', '!=', $target);

        $this->info(sprintf('%d attachment(s) are not on the "%s" disk.', $pending->count(), $target));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $counts = [
            SupportAttachmentStorage::MOVED => 0,
            SupportAttachmentStorage::MISSING => 0,
            SupportAttachmentStorage::FAILED => 0,
            SupportAttachmentStorage::SKIPPED => 0,
        ];

        $pending->orderBy('id')->chunkById(100, function ($attachments) use ($storage, &$counts) {
            foreach ($attachments as $attachment) {
                $counts[$storage->privatize($attachment)]++;
            }
        });

        $this->table(['Result', 'Count'], collect($counts)->map(fn ($count, $result) => [$result, $count])->values()->all());

        if ($counts[SupportAttachmentStorage::MISSING] > 0) {
            $this->warn('Some attachments had no file on disk; their rows were left untouched.');
        }

        return $counts[SupportAttachmentStorage::FAILED] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
