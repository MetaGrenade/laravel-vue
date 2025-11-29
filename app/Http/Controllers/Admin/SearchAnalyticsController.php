<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\SearchQuery;
use App\Models\SearchQueryAggregate;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SearchAnalyticsController extends Controller
{
    use InteractsWithInertiaPagination;

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

    public function exportAggregates(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'search-aggregates-' . now()->format('Ymd-His') . '.csv';

        $aggregates = SearchQueryAggregate::query()
            ->orderByDesc('total_count')
            ->orderByDesc('last_ran_at')
            ->get();

        return response()->streamDownload(function () use ($aggregates) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Term',
                'Searches',
                'Zero Result Searches',
                'Click-Through Rate (%)',
                'Average Results',
                'Last Searched',
            ]);

            foreach ($aggregates as $aggregate) {
                fputcsv($handle, [
                    $aggregate->term,
                    $aggregate->total_count,
                    $aggregate->zero_result_count,
                    $this->calculateCtr($aggregate->total_count, $aggregate->zero_result_count),
                    $this->calculateAverageResults($aggregate->total_count, $aggregate->total_results),
                    optional($aggregate->last_ran_at)?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportSearches(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filters = $this->validateFilters($request);

        $searches = $this->recentSearchQuery($filters)
            ->orderByDesc('created_at')
            ->get(['term', 'result_count', 'created_at']);

        $filename = 'search-queries-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($searches) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Term', 'Results Returned', 'Searched At']);

            foreach ($searches as $search) {
                fputcsv($handle, [
                    $search->term,
                    $search->result_count,
                    optional($search->created_at)?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
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
            $query->where('term', 'like', '%' . $term . '%');
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
