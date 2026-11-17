<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DeletesStrayFiles;
use App\Support\Forum\ForumAttachments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneForumAttachmentsCommand extends Command
{
    use DeletesStrayFiles;

    protected $signature = 'metaforger:forum:attachments:prune {--dry-run : List the files that would be deleted without deleting anything}';

    protected $description = 'Delete the forum attachment files that no attachment names (docs/forum-attachments.md)';

    public function handle(ForumAttachments $attachments): int
    {
        $dry = (bool) $this->option('dry-run');

        $strays = $attachments->strays();
        $result = $this->deleteStrays(Storage::disk($attachments->diskName()), $strays, $dry);
        $size = $this->humanSize($result['bytes']);

        $this->info($dry
            ? sprintf('%d file(s), %s, belong to no post and would be deleted. Run it again without --dry-run to delete them.', count($strays), $size)
            : sprintf('%d file(s), %s, belonged to no post and were deleted.', $result['deleted'], $size));

        $this->line(sprintf('A file younger than %d minutes is never taken: a file is written before the row that names it.', ForumAttachments::STRAY_MINUTES));

        if ($result['failed'] > 0) {
            $this->error(sprintf('%d file(s) could not be deleted and are still there. Check that the web server may write to the directory, then run it again.', $result['failed']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
