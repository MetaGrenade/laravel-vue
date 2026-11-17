<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * What the commands that sweep files nothing names have in common: delete each one (or only say so, on a dry run), and count only
 * what was deleted.
 *
 * The disks are set not to throw (config/filesystems.php): a file that cannot be deleted, say because its directory is not writable,
 * comes back as `false`, and only a disk that does throw gets to the catch. Either way the file is not counted as deleted.
 *
 * @mixin Command
 */
trait DeletesStrayFiles
{
    /**
     * @param  list<string>  $files
     * @return array{deleted: int, failed: int, bytes: int} What was deleted (what would be, on a dry run) and what could not be.
     */
    protected function deleteStrays(Filesystem $disk, array $files, bool $dry): array
    {
        $bytes = 0;
        $deleted = 0;
        $failed = 0;

        foreach ($files as $file) {
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
                $gone = $disk->delete($file);
                $problem = 'could not be deleted.';
            } catch (Throwable $exception) {
                $gone = false;
                $problem = $exception->getMessage();
            }

            if (! $gone) {
                $this->warn("$file: $problem");
                $failed++;

                continue;
            }

            $deleted++;
            $bytes += $size;
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'bytes' => $bytes];
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format(max($bytes, 0) / 1024, 1).' kB';
    }
}
