<?php

namespace App\Jobs;

use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the zip of a data export once its download window (`DataExport::DOWNLOAD_TTL_MINUTES`) is over.
 *
 * The zip holds everything the site keeps about a member, and the member is told the link works for 30 minutes. Until this job
 * nothing deleted the file at the end of that time: it went only when somebody asked for the export again after it had expired
 * (the member's privacy page, or the download link), so a member who downloaded it in time, and never came back, left it on the
 * disk for good, and so did a member whose account was deleted, since the row went and the file did not.
 *
 * Two passes:
 *  1. an export the window of which is over and that still names a file loses the file (the row stays, as the record that the
 *     member asked, with no file);
 *  2. a file in the exports directory that no row names and that is older than two windows is deleted: a deleted member's, or one
 *     a job wrote and then could not record. A younger one may be a job that has just written it, so it is left.
 */
class PruneDataExports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How old a file nobody names has to be before it is deleted: two download windows, so that a file whose row is about to be
     * written (or whose job is slow to finish) is never taken from under it.
     */
    public const UNNAMED_FILE_MINUTES = DataExport::DOWNLOAD_TTL_MINUTES * 2;

    public function handle(): void
    {
        $this->forgetFilesOfExpiredExports();
        $this->forgetFilesNoExportNames();
    }

    private function forgetFilesOfExpiredExports(): void
    {
        // The same test as DataExport::hasExpired(), made by the database so that only the rows that need it are read.
        DataExport::query()
            ->whereNotNull('file_path')
            ->where('completed_at', '<', now()->subMinutes(DataExport::DOWNLOAD_TTL_MINUTES))
            ->chunkById(100, function ($exports): void {
                foreach ($exports as $export) {
                    $export->purgeExpiredFile();
                }
            });
    }

    private function forgetFilesNoExportNames(): void
    {
        $disk = Storage::disk('local');
        $before = now()->subMinutes(self::UNNAMED_FILE_MINUTES)->getTimestamp();

        foreach ($disk->files(DataExport::DIRECTORY) as $path) {
            // A `.gitignore` or `.gitkeep` is not an export.
            if (str_starts_with(basename($path), '.')) {
                continue;
            }

            if ($disk->lastModified($path) >= $before) {
                continue;
            }

            if (DataExport::query()->where('file_path', $path)->exists()) {
                continue;
            }

            $disk->delete($path);
        }
    }
}
