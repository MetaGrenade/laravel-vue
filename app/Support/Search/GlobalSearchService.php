<?php

namespace App\Support\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\ForumThread;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class GlobalSearchService
{
    /**
     * @var array<int, string>
     */
    public const GROUPS = ['blogs', 'forum_threads', 'faqs'];

    /**
     * Execute the aggregated search for the provided term.
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *     query: string,
     *     results: array<string, array{items: array<int, array<string, mixed>>, meta: array<string, int|null>}>
     * }
     */
    public function search(string $term, array $options = []): array
    {
        $normalizedTerm = trim($term);
        $perPage = $this->normalizePerPage($options['per_page'] ?? 10);
        $pages = $this->normalizePages($options['pages'] ?? []);
        $types = $this->normalizeTypes($options['types'] ?? null);

        $results = [];

        foreach (self::GROUPS as $group) {
            if (! in_array($group, $types, true)) {
                $results[$group] = $this->emptyGroup($perPage);
                continue;
            }

            $results[$group] = match ($group) {
                'blogs' => $this->searchBlogs($normalizedTerm, $perPage, $pages['blogs']),
                'forum_threads' => $this->searchForumThreads($normalizedTerm, $perPage, $pages['forum_threads']),
                'faqs' => $this->searchFaqs($normalizedTerm, $perPage, $pages['faqs']),
                default => $this->emptyGroup($perPage),
            };
        }

        return [
            'query' => $normalizedTerm,
            'results' => $results,
        };
    }

    private function searchBlogs(string $term, int $perPage, int $page): array
    {
        $likeTerm = '%' . $term . '%';

        $query = Blog::query()
            ->select(['id', 'title', 'slug', 'excerpt', 'published_at', 'created_at'])
            ->where(function ($query) {
                $query->where('status', 'published')
                    ->orWhere(function ($query) {
                        $query->where('status', 'scheduled')
                            ->whereNotNull('scheduled_for')
                            ->where('scheduled_for', '<=', now());
                    });
            })
            ->where(function ($query) use ($likeTerm) {
                $query->where('title', 'like', $likeTerm)
                    ->orWhere('excerpt', 'like', $likeTerm);
            })
            ->orderByDesc('published_at')
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = $paginator->getCollection()
            ->map(function (Blog $blog) {
                $excerpt = is_string($blog->excerpt) ? trim($blog->excerpt) : '';

                return [
                    'id' => $blog->id,
                    'title' => $blog->title,
                    'description' => $excerpt !== '' ? Str::limit($excerpt, 120) : null,
                    'url' => route('blogs.view', ['slug' => $blog->slug]),
                ];
            })
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => $this->formatMeta($paginator),
        ];
    }

    private function searchForumThreads(string $term, int $perPage, int $page): array
    {
        $likeTerm = '%' . $term . '%';

        $query = ForumThread::query()
            ->select(['id', 'forum_board_id', 'title', 'slug', 'excerpt', 'last_posted_at', 'created_at'])
            ->where('is_published', true)
            ->where(function ($query) use ($likeTerm) {
                $query->where('title', 'like', $likeTerm)
                    ->orWhere('excerpt', 'like', $likeTerm);
            })
            ->with(['board:id,slug,title'])
            ->orderByDesc('last_posted_at')
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = $paginator->getCollection()
            ->map(function (ForumThread $thread) {
                $board = $thread->board;

                if ($board === null) {
                    return null;
                }

                $excerpt = is_string($thread->excerpt) ? trim($thread->excerpt) : '';

                return [
                    'id' => $thread->id,
                    'title' => $thread->title,
                    'description' => $excerpt !== '' ? Str::limit($excerpt, 120) : null,
                    'url' => route('forum.threads.show', ['board' => $board->slug, 'thread' => $thread->slug]),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => $this->formatMeta($paginator),
        ];
    }

    private function searchFaqs(string $term, int $perPage, int $page): array
    {
        $likeTerm = '%' . $term . '%';

        $query = Faq::query()
            ->select(['id', 'faq_category_id', 'question', 'answer'])
            ->where('published', true)
            ->where(function ($query) use ($likeTerm) {
                $query->where('question', 'like', $likeTerm)
                    ->orWhere('answer', 'like', $likeTerm);
            })
            ->orderBy('order')
            ->orderBy('question');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = $paginator->getCollection()
            ->map(function (Faq $faq) use ($term) {
                $answer = is_string($faq->answer) ? trim(strip_tags($faq->answer)) : '';

                $params = ['faqs_search' => $term];

                if ($faq->faq_category_id) {
                    $params['faq_category_id'] = $faq->faq_category_id;
                }

                return [
                    'id' => $faq->id,
                    'title' => $faq->question,
                    'description' => $answer !== '' ? Str::limit($answer, 120) : null,
                    'url' => route('support', $params),
                ];
            })
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => $this->formatMeta($paginator),
        ];
    }

    /**
     * @param  array<string, mixed>  $pages
     * @return array<string, int>
     */
    private function normalizePages(array $pages): array
    {
        $defaults = array_fill_keys(self::GROUPS, 1);

        foreach ($defaults as $group => $defaultPage) {
            $requested = Arr::get($pages, $group, $defaultPage);
            $defaults[$group] = max(1, (int) $requested);
        }

        return $defaults;
    }

    /**
     * @param  array<int, string>|string|null  $types
     * @return array<int, string>
     */
    private function normalizeTypes(array|string|null $types): array
    {
        $available = self::GROUPS;
        $resolved = collect(Arr::wrap($types))
            ->map(fn ($value) => is_string($value) ? $value : null)
            ->filter(fn ($value) => $value !== null && in_array($value, $available, true))
            ->values()
            ->all();

        if ($resolved === []) {
            return $available;
        }

        return array_values(array_unique($resolved));
    }

    private function normalizePerPage(mixed $perPage): int
    {
        $perPage = (int) $perPage;

        if ($perPage < 1) {
            return 10;
        }

        return min($perPage, 50);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int|null>}
     */
    private function emptyGroup(int $perPage): array
    {
        return [
            'items' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'from' => null,
                'to' => null,
            ],
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function formatMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
