<?php

namespace App\Jobs;

use App\Models\SearchQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneSearchQueryLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $retentionDays = (int) config('search.queries.retention_days', 90);

        if ($retentionDays <= 0) {
            return;
        }

        $cutoff = now()->subDays($retentionDays);

        SearchQuery::query()
            ->where('created_at', '<', $cutoff)
            ->delete();
    }
}
