<?php

namespace App\Jobs;

use App\Models\TokenLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deletes the log of requests made with an API key (`token_logs`) once it is older than `api.token_log.retention_days`, so that a
 * busy key does not make the table grow for ever. A retention of 0 or less keeps everything.
 *
 * The log is not only a record: a key's hourly and daily limits are counted from it. That is safe, because a limit looks back at most a
 * day from the moment of the request, and the run only removes rows older than the retention (at least a day) from the moment it
 * runs: every row a later request can count is newer than that.
 */
class PruneTokenLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $days = (int) config('api.token_log.retention_days', 30);

        if ($days <= 0) {
            return;
        }

        $cutoff = now()->subDays($days);

        // In chunks: one huge delete would hold locks on a table that every request made with a key writes to.
        do {
            $deleted = TokenLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit(5000)
                ->delete();
        } while ($deleted > 0);
    }
}
