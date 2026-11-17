<?php

namespace App\Console\Commands;

use App\Support\Blog\BlogCovers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneBlogCoversCommand extends Command
{
    protected $signature = 'metaforger:blog:covers:prune {--dry-run : List the files that would be deleted without deleting anything}';

    protected $description = 'Delete the files in blog-covers/ that no post and no revision names (docs/blog-covers.md)';

    public function handle(BlogCovers $covers): int
    {
        $dry = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');

        $strays = $covers->strays();
        $bytes = 0;
        $deleted = 0;

        foreach ($strays as $file) {
            try {
                $size = $disk->size($file);
            } catch (Throwable) {
                $size = 0;
            }

            if ($dry) {
                $this->line("would delete $file");
                $bytes += $size;

                continue;
            }

            try {
                $disk->delete($file);
                $deleted++;
                $bytes += $size;
            } catch (Throwable $exception) {
                $this->warn("$file: {$exception->getMessage()}");
            }
        }

        $size = $this->humanSize($bytes);

        $this->info($dry
            ? sprintf('%d file(s), %s, in %s/ belong to no post and would be deleted. Run it again without --dry-run to delete them.', count($strays), $size, BlogCovers::DIRECTORY)
            : sprintf('%d file(s), %s, in %s/ belonged to no post and were deleted.', $deleted, $size, BlogCovers::DIRECTORY));

        $this->line(sprintf('A file younger than %d minutes is never taken: a cover is written before the post that names it.', BlogCovers::STRAY_MINUTES));

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format(max($bytes, 0) / 1024, 1).' kB';
    }
}
