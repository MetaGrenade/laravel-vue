<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Deletes audit log entries older than `audit.retention_days`. A retention of 0 or less keeps everything (the default).
 *
 * This is the only thing that deletes from the log, and it goes to the table and not through the model, which refuses to delete an
 * entry. Run by itself as a database user that is allowed to, and give the application's no DELETE on `audit_logs`, to make the log
 * tamper-proof from the application's side (see docs/audit-log.md).
 */
class PruneAuditLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $days = (int) config('audit.retention_days', 0);

        if ($days <= 0) {
            return;
        }

        $cutoff = now()->subDays($days);

        // In chunks: one huge delete would hold locks on a table that every admin request writes to.
        do {
            $deleted = DB::table('audit_logs')
                ->where('created_at', '<', $cutoff)
                ->limit(5000)
                ->delete();
        } while ($deleted > 0);
    }
}
