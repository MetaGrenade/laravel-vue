<?php

namespace App\Jobs;

use App\Models\UsageEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deletes usage events older than `billing.usage.retention_months`, whole months at a time, so the ledger does not grow for ever
 * (an event is a row per use, and an API call is one). A retention of 0 or less keeps everything.
 */
class PruneUsageEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $months = (int) config('billing.usage.retention_months', 13);

        if ($months <= 0) {
            return;
        }

        // The start of the month `$months` ago, so that a month is never cut in half: the last few months always have all their events.
        $cutoff = now()->startOfMonth()->subMonths($months);

        // In chunks of ids: one huge delete would hold locks on a table that is being written to by every API request.
        do {
            $deleted = UsageEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->limit(5000)
                ->delete();
        } while ($deleted > 0);
    }
}
