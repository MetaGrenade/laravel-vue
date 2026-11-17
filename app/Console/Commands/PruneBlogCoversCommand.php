<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DeletesStrayFiles;
use App\Support\Blog\BlogCovers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneBlogCoversCommand extends Command
{
    use DeletesStrayFiles;

    protected $signature = 'metaforger:blog:covers:prune {--dry-run : List the files that would be deleted without deleting anything}';

    protected $description = 'Delete the files in blog-covers/ that no post and no revision names (docs/blog-covers.md)';

    public function handle(BlogCovers $covers): int
    {
        $dry = (bool) $this->option('dry-run');

        $strays = $covers->strays();
        $result = $this->deleteStrays(Storage::disk('public'), $strays, $dry);
        $size = $this->humanSize($result['bytes']);

        $this->info($dry
            ? sprintf('%d file(s), %s, in %s/ belong to no post and would be deleted. Run it again without --dry-run to delete them.', count($strays), $size, BlogCovers::DIRECTORY)
            : sprintf('%d file(s), %s, in %s/ belonged to no post and were deleted.', $result['deleted'], $size, BlogCovers::DIRECTORY));

        $this->line(sprintf('A file younger than %d minutes is never taken: a cover is written before the post that names it.', BlogCovers::STRAY_MINUTES));

        if ($result['failed'] > 0) {
            $this->error(sprintf('%d file(s) could not be deleted and are still there. Check that the web server may write to the directory, then run it again.', $result['failed']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
