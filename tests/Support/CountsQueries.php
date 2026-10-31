<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a page costs in database queries (docs/query-budgets.md). A page can be slow in two ways a test of its output never shows: it
 * asks one query per row it lists (so it gets slower as the site grows), or it asks more queries than it needs. `assertQueryCost`
 * checks both: the number of queries is the same however many rows there are, and it stays under a ceiling that is set on purpose.
 */
trait CountsQueries
{
    /**
     * The SQL of every query the closure runs, in order, with the lists of ids taken out (`in (1, 2, 3)` is `in (?)`), so that two
     * requests that differ only in how many rows they fetched by id read the same. The cache is emptied first, so the request pays
     * for everything it would on a cache miss: a page that keeps its result for a while (the forum index) costs what building that
     * result costs, which is what grows with the rows, and a request that happens to be answered from the cache says nothing.
     *
     * @return list<string>
     */
    protected function queriesRun(Closure $request): array
    {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $request();
            $log = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return array_map(fn (array $query) => (string) preg_replace('/\bin \([\d, ?]+\)/', 'in (?)', $query['query']), $log);
    }

    /**
     * Assert a page costs the same queries with six rows as with one, and no more than `$ceiling`.
     *
     * The ceiling is the cost of the page as it stands plus some room, not a target: when a page needs another query for a reason,
     * raise the number in the same change and say why. When it fails because the cost grows, the page asks something once per row;
     * fetch it for the whole list instead (an eager load, a `withCount`, a grouped query).
     *
     * @param  Closure(int): mixed  $addRow  Adds one more of what the page lists, given how many there will be.
     * @param  Closure(): mixed  $request  Asks for the page (and asserts it was served).
     */
    protected function assertQueryCost(string $page, Closure $addRow, Closure $request, int $ceiling, int $rows = 6): void
    {
        $addRow(1);
        $request(); // the first request also loads what every request loads once (permissions, settings), and may cache it

        $few = $this->queriesRun($request);

        for ($row = 2; $row <= $rows; $row++) {
            $addRow($row);
        }

        $many = $this->queriesRun($request);

        $this->assertLessThanOrEqual(
            $ceiling,
            count($few),
            "{$page} asked ".count($few)." queries with one row, more than the {$ceiling} it is allowed:\n".$this->summarise($few),
        );

        $this->assertSame(
            count($few),
            count($many),
            "{$page} asked ".count($few).' queries with one row and '.count($many)." with {$rows}, so it asks something once per row. The extra ones:\n"
                .$this->summarise($this->addedTo($few, $many)),
        );
    }

    /**
     * The queries in `$after` that `$before` does not have as many of.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<string>
     */
    private function addedTo(array $before, array $after): array
    {
        $had = array_count_values($before);
        $added = [];

        foreach ($after as $query) {
            if (($had[$query] ?? 0) > 0) {
                $had[$query]--;

                continue;
            }

            $added[] = $query;
        }

        return $added;
    }

    /**
     * @param  list<string>  $queries
     */
    private function summarise(array $queries): string
    {
        $lines = [];

        foreach (array_count_values($queries) as $query => $times) {
            $lines[] = ($times > 1 ? "{$times}x " : '    ').$query;
        }

        return implode("\n", $lines);
    }
}
