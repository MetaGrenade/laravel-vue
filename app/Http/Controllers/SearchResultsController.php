<?php

namespace App\Http\Controllers;

use App\Support\Search\GlobalSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class SearchResultsController extends Controller
{
    private const MIN_QUERY_LENGTH = 2;

    public function __invoke(Request $request, GlobalSearchService $searchService): Response
    {
        $query = $request->string('q')->trim();
        $perPage = $this->normalizePerPage($request->integer('per_page', 10));

        $pages = [
            'blogs' => max(1, (int) $request->integer('blogs_page', 1)),
            'forum_threads' => max(1, (int) $request->integer('forum_threads_page', 1)),
            'faqs' => max(1, (int) $request->integer('faqs_page', 1)),
        ];

        $types = $this->normalizeTypes($request->input('types'));

        $results = null;
        $hasSufficientLength = $query->length() >= self::MIN_QUERY_LENGTH;

        if ($query->isNotEmpty() && $hasSufficientLength) {
            $results = $searchService->search($query->toString(), [
                'per_page' => $perPage,
                'pages' => $pages,
                'types' => $types,
            ])['results'];
        }

        return Inertia::render('Search/Results', [
            'query' => $query->toString(),
            'filters' => [
                'types' => $types,
                'per_page' => $perPage,
            ],
            'pages' => $pages,
            'results' => $results,
            'available_types' => $this->availableTypes(),
            'min_query_length' => self::MIN_QUERY_LENGTH,
        ]);
    }

    /**
     * @param  array<int, string>|string|null  $types
     * @return array<int, string>
     */
    private function normalizeTypes(array|string|null $types): array
    {
        $available = array_keys($this->availableTypes());
        $values = collect(Arr::wrap($types))
            ->map(fn ($type) => is_string($type) ? $type : null)
            ->filter(fn ($type) => $type !== null && in_array($type, $available, true))
            ->values()
            ->all();

        if ($values === []) {
            return $available;
        }

        return array_values(array_unique($values));
    }

    private function normalizePerPage(int $perPage): int
    {
        if ($perPage < 1) {
            return 10;
        }

        return min($perPage, 50);
    }

    /**
     * @return array<string, string>
     */
    private function availableTypes(): array
    {
        return [
            'blogs' => 'Blog posts',
            'forum_threads' => 'Forum threads',
            'faqs' => 'FAQs',
        ];
    }
}
