<?php

namespace App\Http\Controllers;

use App\Support\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        if ($query->isEmpty()) {
            return response()->json([
                'query' => '',
                'results' => $this->emptyResults(),
            ]);
        }

        $term = $query->toString();

        $payload = $this->searchService->search($term, [
            'per_page' => $limit,
            'pages' => [
                'blogs' => 1,
                'forum_threads' => 1,
                'faqs' => 1,
            ],
        ]);

        $results = $payload['results'];

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
