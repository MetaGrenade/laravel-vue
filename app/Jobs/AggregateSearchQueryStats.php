<?php

namespace App\Jobs;

use App\Models\SearchQuery;
use App\Models\SearchQueryAggregate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AggregateSearchQueryStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $windowDays = (int) config('search.queries.aggregation_window_days', 30);
        $cutoff = $windowDays > 0 ? now()->subDays($windowDays) : null;

        $query = SearchQuery::query()
            ->select([
                'term',
                DB::raw('COUNT(*) as total_count'),
                DB::raw('SUM(result_count) as total_results'),
                DB::raw('SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) as zero_result_count'),
                DB::raw('MAX(created_at) as last_ran_at'),
            ])
            ->groupBy('term');

        if ($cutoff) {
            $query->where('created_at', '>=', $cutoff);
        }

        $aggregates = $query->get();

        $terms = [];

        foreach ($aggregates as $aggregate) {
            $term = (string) $aggregate->term;
            $terms[] = $term;

            SearchQueryAggregate::query()->updateOrCreate(
                ['term' => $term],
                [
                    'total_count' => (int) $aggregate->total_count,
                    'total_results' => (int) $aggregate->total_results,
                    'zero_result_count' => (int) $aggregate->zero_result_count,
                    'last_ran_at' => $aggregate->last_ran_at ? Carbon::parse($aggregate->last_ran_at) : null,
                ]
            );
        }

        if (empty($terms)) {
            SearchQueryAggregate::query()->delete();

            return;
        }

        SearchQueryAggregate::query()
            ->whereNotIn('term', $terms)
            ->delete();
    }
}
