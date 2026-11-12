<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\SearchQuery;
use App\Models\SearchQueryAggregate;
use App\Support\Csv\CsvCell;
use App\Support\Localization\DateFormatter;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SearchAnalyticsController extends Controller
{
    use InteractsWithInertiaPagination;

    /** Rows read from the database at a time while a CSV is being written. */
    private const EXPORT_CHUNK = 1000;

    public function index(Request $request): Response
    {
        $formatter = DateFormatter::for($request->user());

        $filters = $this->validateFilters($request);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(5, min($perPage, 100));

        $summary = $this->buildSummary();
        $topQueries = $this->buildAggregates($formatter, limit: 10);
        $failedQueries = $this->buildAggregates($formatter, limit: 10, failedOnly: true);
        $recentSearches = $this->recentSearches($formatter, $filters, $perPage);

        return Inertia::render('acp/SearchAnalytics', [
            'summary' => $summary,
            'topQueries' => $topQueries,
            'failedQueries' => $failedQueries,
            'recentSearches' => $recentSearches,
            'filters' => $filters,
            'exportLinks' => [
                'aggregates' => route('acp.search-analytics.export-aggregates'),
                'searches' => route('acp.search-analytics.export-searches', Arr::where($filters, fn ($value) => $value !== null && $value !== '')),
            ],
        ]);
    }

    public function exportAggregates(): StreamedResponse
    {
        $filename = 'search-aggregates-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            $this->csvRow($handle, [
                'Term',
                'Searches',
                'Zero Result Searches',
                'Click-Through Rate (%)',
                'Average Results',
                'Last Searched',
            ]);

            foreach ($this->aggregatesInOrder() as $aggregate) {
                $this->csvRow($handle, [
                    CsvCell::text($aggregate->term),
                    $aggregate->total_count,
                    $aggregate->zero_result_count,
                    $this->calculateCtr($aggregate->total_count, $aggregate->zero_result_count),
                    $this->calculateAverageResults($aggregate->total_count, $aggregate->total_results),
                    $aggregate->last_ran_at?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportSearches(Request $request): StreamedResponse
    {
        $filters = $this->validateFilters($request);

        $filename = 'search-queries-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            $this->csvRow($handle, ['Term', 'Results Returned', 'Searched At']);

            foreach ($this->searchesNewestFirst($filters) as $search) {
                $this->csvRow($handle, [
                    CsvCell::text($search->term),
                    $search->result_count,
                    $search->created_at?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * One line of a CSV. The escape character is switched off (`''`): RFC 4180 doubles a quote and has no backslash escape, and with PHP's default
     * a term that ends in a backslash before a quote (`a\"b`) is written with the quote NOT doubled, so a reader ends the cell there and puts the
     * rest in the next one. PHP 8.4 also deprecates leaving the argument out.
     *
     * @param  resource  $handle
     * @param  array<int, string|int|float|null>  $cells
     */
    private function csvRow($handle, array $cells): void
    {
        fputcsv($handle, $cells, ',', '"', '', "\n");
    }

    /**
     * Every logged search that matches, newest first, a thousand at a time. Each page starts after the last row of the one before, by its time and id
     * (the log is only ever added to, so a row never moves under the cursor), and not at an offset: the file of a busy site is hundreds of thousands
     * of rows, and an offset would read all of the rows before it again for every page. The id also settles searches made in the same second.
     *
     * @param  array<string, mixed>  $filters
     * @return Generator<int, SearchQuery>
     */
    private function searchesNewestFirst(array $filters): Generator
    {
        $last = null;

        do {
            $query = $this->recentSearchQuery($filters)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::EXPORT_CHUNK);

            if ($last !== null) {
                // The first line is what lets the database start in the index at the last time and read on from there (an OR on its own is read
                // as "look at every row"); the second line only sorts out the rows that share that second.
                $query->where('created_at', '<=', $last->created_at)
                    ->where(fn ($after) => $after->where('created_at', '<', $last->created_at)->orWhere('id', '<', $last->id));
            }

            $rows = $query->get(['id', 'term', 'result_count', 'created_at']);

            foreach ($rows as $row) {
                yield $row;
            }

            $last = $rows->last();
        } while ($rows->count() === self::EXPORT_CHUNK);
    }

    /**
     * Every term with its counts, the most searched first. The order is worked out once, by the database, and kept as a list of ids (a number a row);
     * the rows are then read a thousand at a time. The counts are rewritten by the nightly aggregation, so a page that started at a count would
     * miss or repeat terms whose count changed while the file was being made; a list of ids cannot. A term the aggregation removes meanwhile is skipped.
     *
     * @return Generator<int, SearchQueryAggregate>
     */
    private function aggregatesInOrder(): Generator
    {
        $ids = [];

        $order = SearchQueryAggregate::query()
            ->orderByDesc('total_count')
            ->orderByDesc('last_ran_at')
            ->orderByDesc('id')
            ->toBase()
            ->select('id');

        foreach ($order->cursor() as $row) {
            $ids[] = (int) $row->id;
        }

        foreach (array_chunk($ids, self::EXPORT_CHUNK) as $slice) {
            $rows = SearchQueryAggregate::query()->whereIn('id', $slice)->get()->keyBy('id');

            foreach ($slice as $id) {
                if ($rows->has($id)) {
                    yield $rows->get($id);
                }
            }
        }
    }

    protected function buildSummary(): array
    {
        $totals = SearchQueryAggregate::query()
            ->select([
                DB::raw('COALESCE(SUM(total_count), 0) as total_count'),
                DB::raw('COALESCE(SUM(total_results), 0) as total_results'),
                DB::raw('COALESCE(SUM(zero_result_count), 0) as zero_result_count'),
            ])
            ->first();

        $totalCount = (int) ($totals?->total_count ?? 0);
        $totalResults = (int) ($totals?->total_results ?? 0);
        $zeroResultCount = (int) ($totals?->zero_result_count ?? 0);

        return [
            'total_searches' => $totalCount,
            'unique_terms' => SearchQueryAggregate::query()->count(),
            'overall_ctr' => $this->calculateCtr($totalCount, $zeroResultCount),
            'zero_result_rate' => $totalCount > 0 ? round(($zeroResultCount / $totalCount) * 100, 2) : 0,
            'average_results' => $this->calculateAverageResults($totalCount, $totalResults),
        ];
    }

    protected function buildAggregates(DateFormatter $formatter, int $limit = 10, bool $failedOnly = false): array
    {
        $query = SearchQueryAggregate::query()
            ->orderByDesc($failedOnly ? 'zero_result_count' : 'total_count')
            ->orderByDesc('last_ran_at');

        if ($failedOnly) {
            $query->where('zero_result_count', '>', 0);
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(function (SearchQueryAggregate $aggregate) use ($formatter) {
                $totalCount = (int) $aggregate->total_count;
                $zeroCount = (int) $aggregate->zero_result_count;
                $totalResults = (int) $aggregate->total_results;

                return [
                    'term' => $aggregate->term,
                    'total_count' => $totalCount,
                    'zero_result_count' => $zeroCount,
                    'total_results' => $totalResults,
                    'click_through_rate' => $this->calculateCtr($totalCount, $zeroCount),
                    'average_results' => $this->calculateAverageResults($totalCount, $totalResults),
                    'last_ran_at' => $formatter->iso($aggregate->last_ran_at),
                ];
            })
            ->all();
    }

    protected function recentSearches(DateFormatter $formatter, array $filters, int $perPage): array
    {
        $paginator = $this->recentSearchQuery($filters)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (SearchQuery $query) use ($formatter) {
                return [
                    'term' => $query->term,
                    'result_count' => $query->result_count,
                    'created_at' => $formatter->iso($query->created_at),
                ];
            });

        return [
            'data' => $paginator->items(),
            ...$this->inertiaPagination($paginator),
        ];
    }

    protected function recentSearchQuery(array $filters)
    {
        $query = SearchQuery::query();

        if ($term = $filters['term'] ?? null) {
            $query->whereLike('term', '%'.$term.'%');
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', SupportCarbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', SupportCarbon::parse($filters['date_to'])->endOfDay());
        }

        return $query;
    }

    protected function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'term' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        return [
            'term' => $validated['term'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'per_page' => $validated['per_page'] ?? null,
        ];
    }

    protected function calculateCtr(int $totalCount, int $zeroResultCount): float
    {
        if ($totalCount === 0) {
            return 0.0;
        }

        return round((($totalCount - $zeroResultCount) / $totalCount) * 100, 2);
    }

    protected function calculateAverageResults(int $totalCount, int $totalResults): float
    {
        if ($totalCount === 0) {
            return 0.0;
        }

        return round($totalResults / $totalCount, 2);
    }
}
