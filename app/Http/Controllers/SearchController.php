<?php

namespace App\Http\Controllers;

use App\Models\SearchQuery;
use App\Support\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    public function __construct(private readonly GlobalSearchService $searchService)
    {
    }

    /**
     * Handle the aggregated search across blogs, forum threads, and FAQs.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->string('q')->trim();
        $limit = (int) $request->integer('limit', 5);
        $limit = max(1, min($limit, 10));
        $types = $request->input('types');

        if ($query->isEmpty()) {
            return response()->json([
                'query' => '',
                'results' => $this->emptyResults(),
            ]);
        }

        $term = $query->toString();
        $normalizedTerm = Str::of($term)->lower()->trim()->toString();

        $payload = $this->searchService->search($term, [
            'per_page' => $limit,
            'pages' => [
                'blogs' => 1,
                'forum_threads' => 1,
                'faqs' => 1,
            ],
            'types' => $types,
        ]);

        $results = $payload['results'];

        $this->logQuery($normalizedTerm, $results);

        return response()->json([
            'query' => $payload['query'],
            'results' => [
                'blogs' => [
                    'items' => $results['blogs']['items'],
                    'has_more' => ($results['blogs']['meta']['current_page'] ?? 1) < ($results['blogs']['meta']['last_page'] ?? 1),
                ],
                'forum_threads' => [
                    'items' => $results['forum_threads']['items'],
                    'has_more' => ($results['forum_threads']['meta']['current_page'] ?? 1) < ($results['forum_threads']['meta']['last_page'] ?? 1),
                ],
                'faqs' => [
                    'items' => $results['faqs']['items'],
                    'has_more' => ($results['faqs']['meta']['current_page'] ?? 1) < ($results['faqs']['meta']['last_page'] ?? 1),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}>  $results
     */
    private function logQuery(string $term, array $results): void
    {
        if ($this->shouldSkipLogging($term)) {
            return;
        }

        $total = collect($results)
            ->sum(function (array $group): int {
                $metaTotal = $group['meta']['total'] ?? null;

                if (is_numeric($metaTotal)) {
                    return (int) $metaTotal;
                }

                return count($group['items'] ?? []);
            });

        SearchQuery::query()->create([
            'term' => Str::limit($term, 255, ''),
            'result_count' => max(0, (int) $total),
        ]);
    }

    private function shouldSkipLogging(string $term): bool
    {
        $minLength = max(1, (int) config('search.queries.minimum_length', 2));

        return Str::of($term)->trim()->length() < $minLength;
    }

    /**
     * @return array<string, array{items: array<int, array<string, mixed>>, has_more: bool}>
     */
    private function emptyResults(): array
    {
        return [
            'blogs' => [
                'items' => [],
                'has_more' => false,
            ],
            'forum_threads' => [
                'items' => [],
                'has_more' => false,
            ],
            'faqs' => [
                'items' => [],
                'has_more' => false,
            ],
        ];
    }
}
