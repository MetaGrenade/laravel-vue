<?php

namespace Tests\Feature\Search;

use App\Jobs\AggregateSearchQueryStats;
use App\Models\SearchQueryAggregate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nightly job that adds the logged searches up into one row for each term (what Admin -> Search analytics and its terms download read).
 *
 * A worker stops a job after 60 seconds. The job wrote each term with its own `updateOrCreate` (two queries a term), kept every term in memory as a
 * model and then removed the stale rows with `whereNotIn` and every term as a bind: 99 seconds, 126,000 queries and 107 MB at 63,000 terms on MariaDB, so
 * a site with more than about 37,000 different searches in a month would not have got to the end of it, and PostgreSQL refuses a statement with more than
 * 65,535 binds.
 * Now the database counts the terms in one statement and they are written a thousand at a time. (`SearchQueryAggregationTest` has the first checks.)
 */
class AggregateSearchQueryStatsTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-12 00:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::NOW);
        config()->set('search.queries.aggregation_window_days', 30);
    }

    /**
     * @return array<string, mixed>
     */
    private function search(string $term, string $at, int $results): array
    {
        return ['term' => $term, 'result_count' => $results, 'created_at' => $at, 'updated_at' => $at];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function log(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('search_queries')->insert($chunk);
        }
    }

    private function aggregate(string $term, int $count = 7, string $createdAt = '2026-01-01 00:00:00'): void
    {
        DB::table('search_query_aggregates')->insert([
            'term' => $term, 'total_count' => $count, 'total_results' => $count, 'zero_result_count' => $count,
            'last_ran_at' => '2026-01-01 00:00:00', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function nightly(): void
    {
        (new AggregateSearchQueryStats)->handle();
    }

    #[Test]
    public function each_term_has_its_searches_its_results_its_searches_with_none_and_the_time_of_the_last(): void
    {
        $this->log([
            $this->search('laravel', '2026-11-01 10:00:00', 5),
            $this->search('laravel', '2026-11-10 08:30:00', 0),
            $this->search('laravel', '2026-11-05 23:59:59', 7),
            $this->search('vue', '2026-11-02 12:00:00', 0),
            $this->search('vue', '2026-11-02 12:00:00', 0),
            $this->search('inertia', '2026-10-20 06:00:00', 3),
        ]);

        $this->nightly();

        $this->assertSame(3, SearchQueryAggregate::query()->count());

        $laravel = SearchQueryAggregate::query()->where('term', 'laravel')->firstOrFail();
        $this->assertSame([3, 12, 1], [$laravel->total_count, $laravel->total_results, $laravel->zero_result_count]);
        $this->assertSame('2026-11-10 08:30:00', $laravel->last_ran_at->toDateTimeString());

        $vue = SearchQueryAggregate::query()->where('term', 'vue')->firstOrFail();
        $this->assertSame([2, 0, 2], [$vue->total_count, $vue->total_results, $vue->zero_result_count]);

        $inertia = SearchQueryAggregate::query()->where('term', 'inertia')->firstOrFail();
        $this->assertSame([1, 3, 0], [$inertia->total_count, $inertia->total_results, $inertia->zero_result_count]);
        $this->assertSame('2026-10-20 06:00:00', $inertia->last_ran_at->toDateTimeString());
    }

    #[Test]
    public function only_the_searches_of_the_window_are_added_up(): void
    {
        $this->log([
            $this->search('laravel', '2026-10-13 00:30:00', 2), // exactly 30 days: in
            $this->search('laravel', '2026-10-13 00:29:59', 9), // a second older: out
            $this->search('laravel', '2026-08-01 00:00:00', 9),
            $this->search('legacy', '2026-09-01 00:00:00', 1),
        ]);

        $this->nightly();

        $laravel = SearchQueryAggregate::query()->where('term', 'laravel')->firstOrFail();
        $this->assertSame([1, 2], [$laravel->total_count, $laravel->total_results]);
        $this->assertDatabaseMissing('search_query_aggregates', ['term' => 'legacy']);
    }

    #[Test]
    public function a_window_of_nought_adds_up_every_search(): void
    {
        config()->set('search.queries.aggregation_window_days', 0);

        $this->log([
            $this->search('legacy', '2024-03-01 00:00:00', 1),
            $this->search('legacy', '2026-11-01 00:00:00', 2),
        ]);
        $this->aggregate('searched-for-by-nobody');

        $this->nightly();

        $legacy = SearchQueryAggregate::query()->where('term', 'legacy')->firstOrFail();
        $this->assertSame([2, 3], [$legacy->total_count, $legacy->total_results]);
        $this->assertSame('2026-11-01 00:00:00', $legacy->last_ran_at->toDateTimeString());
        $this->assertDatabaseMissing('search_query_aggregates', ['term' => 'searched-for-by-nobody']);
    }

    #[Test]
    public function a_term_that_is_already_there_is_brought_up_to_date_and_keeps_the_day_it_was_made(): void
    {
        $this->log([$this->search('laravel', '2026-11-10 08:30:00', 4)]);
        $this->aggregate('laravel', count: 50, createdAt: '2026-02-03 04:05:06');

        $this->nightly();

        $this->assertSame(1, SearchQueryAggregate::query()->count());

        $row = SearchQueryAggregate::query()->firstOrFail();
        $this->assertSame([1, 4, 0], [$row->total_count, $row->total_results, $row->zero_result_count]);
        $this->assertSame('2026-11-10 08:30:00', $row->last_ran_at->toDateTimeString());
        $this->assertSame('2026-02-03 04:05:06', $row->created_at->toDateTimeString());
        $this->assertSame(self::NOW, $row->updated_at->toDateTimeString());
    }

    #[Test]
    public function a_term_nobody_searched_for_in_the_window_is_removed_and_the_others_stay(): void
    {
        $this->log([
            $this->search('kept', '2026-11-11 10:00:00', 1),
            $this->search('too-old', '2026-08-01 10:00:00', 1),
        ]);
        $this->aggregate('kept');
        $this->aggregate('too-old');
        $this->aggregate('never-logged');

        $this->nightly();

        $this->assertSame(['kept'], SearchQueryAggregate::query()->pluck('term')->all());
        $this->assertSame(1, SearchQueryAggregate::query()->where('term', 'kept')->value('total_count'));
    }

    #[Test]
    public function with_nothing_logged_in_the_window_every_row_is_removed(): void
    {
        $this->log([$this->search('too-old', '2026-08-01 10:00:00', 1)]);
        $this->aggregate('too-old');
        $this->aggregate('never-logged');

        $this->nightly();

        $this->assertSame(0, SearchQueryAggregate::query()->count());
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->log([
            $this->search('laravel', '2026-11-01 10:00:00', 5),
            $this->search('vue', '2026-11-02 12:00:00', 0),
        ]);

        $this->nightly();
        $first = SearchQueryAggregate::query()->orderBy('term')->get(['term', 'total_count', 'total_results', 'zero_result_count', 'last_ran_at'])->toArray();

        $this->nightly();
        $second = SearchQueryAggregate::query()->orderBy('term')->get(['term', 'total_count', 'total_results', 'zero_result_count', 'last_ran_at'])->toArray();

        $this->assertSame($first, $second);
        $this->assertCount(2, $second);
    }

    /**
     * Seven and a half thousand different terms, a few of them searched for several times, and aggregates of an earlier night: some of terms that are
     * still searched for, some of terms that are not. A statement that holds every term (the old `whereNotIn`) has more binds than the 7,000 of a
     * thousand rows of seven values, and a query a term (`updateOrCreate`) makes thousands of statements.
     */
    #[Test]
    public function many_terms_are_written_a_thousand_to_a_statement_and_no_statement_holds_them_all(): void
    {
        $rows = [];
        $expected = [];

        for ($i = 0; $i < 7500; $i++) {
            $times = 1 + ($i % 3); // 1, 2 or 3 searches
            for ($t = 0; $t < $times; $t++) {
                $rows[] = $this->search("term-$i", '2026-11-0'.(1 + $t).' 10:00:00', $t === 0 ? 0 : $i % 5 + 1);
            }
            $expected["term-$i"] = [
                $times,
                ($times - 1) * ($i % 5 + 1),
                1,
            ];
        }

        $this->log($rows);

        foreach ([0, 3, 7499] as $i) {
            $this->aggregate("term-$i"); // stale counts, to be replaced
        }
        $this->aggregate('gone-1');
        $this->aggregate('gone-2');

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            $statements[] = $query;
        });

        $this->nightly();
        $ran = $statements; // what the job did, before the checks below add their own queries

        $this->assertSame(7500, SearchQueryAggregate::query()->count());
        $this->assertDatabaseMissing('search_query_aggregates', ['term' => 'gone-1']);

        foreach ($expected as $term => $values) {
            $row = SearchQueryAggregate::query()->where('term', $term)->first();
            $this->assertNotNull($row, $term);
            $this->assertSame($values, [$row->total_count, $row->total_results, $row->zero_result_count], $term);
        }

        $writes = array_values(array_filter($ran, fn (QueryExecuted $query) => str_starts_with($query->sql, 'insert')));
        $reads = array_values(array_filter($ran, fn (QueryExecuted $query) => str_starts_with($query->sql, 'select')));
        $deletes = array_values(array_filter($ran, fn (QueryExecuted $query) => str_starts_with($query->sql, 'delete')));

        // One statement counts the terms, eight write them (a thousand at a time) and one removes the stale ones.
        $this->assertCount(1, array_filter($reads, fn (QueryExecuted $query) => str_contains($query->sql, 'group by')));
        $this->assertCount(8, $writes);
        $this->assertCount(1, $deletes);

        foreach ($ran as $statement) {
            $this->assertLessThanOrEqual(7000, count($statement->bindings), substr($statement->sql, 0, 120));
        }
    }
}
