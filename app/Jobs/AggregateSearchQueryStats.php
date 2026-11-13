<?php

namespace App\Jobs;

use App\Models\SearchQuery;
use App\Models\SearchQueryAggregate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Adds the logged searches up into one row for each term (what the admin statistics and the terms download read), every night.
 *
 * A worker stops a job after 60 seconds, so this has to be quick however many different terms were searched for: the database counts them in one
 * statement and the rows are written a thousand at a time. It used to read every term into memory as a model, write each with its own `updateOrCreate`
 * (two queries a term: 100 seconds, 126,000 queries and 107 MB at 63,000 terms on MariaDB, so a site with more than about 37,000 would not have got to the end) and then
 * remove the stale rows with `whereNotIn` and every term as a bind, which PostgreSQL refuses past 65,535 (docs/performance.md).
 */
class AggregateSearchQueryStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Terms written in one statement (seven values a row, so 7,000 binds: far under the 65,535 of PostgreSQL and MySQL). */
    private const CHUNK = 1000;

    public function handle(): void
    {
        $windowDays = (int) config('search.queries.aggregation_window_days', 30);
        $cutoff = $windowDays > 0 ? now()->subDays($windowDays) : null;

        $terms = SearchQuery::query()
            ->toBase()
            ->select('term')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(result_count) as total_results')
            ->selectRaw('SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) as zero_result_count')
            ->selectRaw('MAX(created_at) as last_ran_at')
            ->when($cutoff, fn (Builder $query) => $query->where('created_at', '>=', $cutoff))
            ->groupBy('term')
            ->cursor();

        foreach ($terms->chunk(self::CHUNK) as $chunk) {
            // A term that is there is brought up to date and one that is not is added; the row keeps its `created_at`.
            SearchQueryAggregate::query()->upsert(
                $chunk->map(fn (object $term): array => [
                    'term' => (string) $term->term,
                    'total_count' => (int) $term->total_count,
                    'total_results' => (int) $term->total_results,
                    'zero_result_count' => (int) $term->zero_result_count,
                    'last_ran_at' => $term->last_ran_at,
                ])->values()->all(),
                ['term'],
                ['total_count', 'total_results', 'zero_result_count', 'last_ran_at'],
            );
        }

        // What nobody searched for in the window any more goes. Asked of the database as a question about each row, and not as a list of every term
        // that was found (that list is what ran into the limit on the number of binds in a statement).
        SearchQueryAggregate::query()
            ->whereNotExists(function (Builder $query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from('search_queries')
                    ->whereColumn('search_queries.term', 'search_query_aggregates.term')
                    ->when($cutoff, fn (Builder $inner) => $inner->where('search_queries.created_at', '>=', $cutoff));
            })
            ->delete();
    }
}
