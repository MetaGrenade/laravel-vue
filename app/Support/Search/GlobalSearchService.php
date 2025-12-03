<?php

namespace App\Support\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\ForumThread;
use App\Support\WebsiteSections;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

        $types = array_values(array_filter($types, function (string $group) {
            $section = $this->groupToSection($group);

            return ! $section || WebsiteSections::isEnabled($section);
        }));

        $results = collect(self::GROUPS)
            ->mapWithKeys(function (string $group) use ($types, $normalizedTerm, $perPage, $pages) {
                if (! in_array($group, $types, true)) {
                    return [$group => $this->emptyGroup($perPage)];
                }

                $section = $this->groupToSection($group);

                if ($section && ! WebsiteSections::isEnabled($section)) {
                    return [$group => $this->emptyGroup($perPage)];
                }

                $results = match ($group) {
                    'blogs' => $this->searchBlogs($normalizedTerm, $perPage, $pages['blogs']),
                    'forum_threads' => $this->searchForumThreads($normalizedTerm, $perPage, $pages['forum_threads']),
                    'faqs' => $this->searchFaqs($normalizedTerm, $perPage, $pages['faqs']),
                    default => $this->emptyGroup($perPage),
                };

                return [$group => $results];
            })
            ->all();

        return [
            'query' => $normalizedTerm,
            'results' => $results,
        ];
    }

    private function searchBlogs(string $term, int $perPage, int $page): array
    {
        $query = Blog::query()
            ->select(['blogs.id', 'blogs.title', 'blogs.slug', 'blogs.excerpt', 'blogs.body', 'blogs.published_at', 'blogs.created_at', 'blogs.user_id'])
            ->leftJoin('users', 'users.id', '=', 'blogs.user_id')
            ->where(function ($query) {
                $query->where('blogs.status', 'published')
                    ->orWhere(function ($query) {
                        $query->where('blogs.status', 'scheduled')
                            ->whereNotNull('blogs.scheduled_for')
                            ->where('blogs.scheduled_for', '<=', now());
                    });
            })
            ->when($this->supportsFullText(), function ($query) use ($term) {
                $query->whereRaw('MATCH(blogs.title, blogs.excerpt, blogs.body) AGAINST (? IN BOOLEAN MODE)', [$this->fullTextBooleanTerm($term)]);
            }, function ($query) use ($term) {
                $likeTerm = $this->likeTerm($term);

                $query->where(function ($query) use ($likeTerm) {
                    $query->where('blogs.title', 'like', $likeTerm)
                        ->orWhere('blogs.excerpt', 'like', $likeTerm)
                        ->orWhere('blogs.body', 'like', $likeTerm)
                        ->orWhere('users.nickname', 'like', $likeTerm);
                });
            })
            ->when(
                ! $this->supportsFullText(),
                fn ($query) => $this->selectRelevance($query, [
                    'blogs.title' => 5,
                    'blogs.excerpt' => 3,
                    'blogs.body' => 3,
                    'users.nickname' => 2,
                ], $term),
            )
            ->when(
                $this->supportsFullText(),
                fn ($query) => $query->selectRaw(
                    'MATCH(blogs.title, blogs.excerpt, blogs.body) AGAINST (? IN BOOLEAN MODE) as relevance',
                    [$this->fullTextBooleanTerm($term)],
                ),
            )
            ->with(['user:id,name,nickname'])
            ->orderByDesc('relevance')
            ->orderByDesc('blogs.published_at')
            ->orderByDesc('blogs.created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = $paginator->getCollection()
            ->map(function (Blog $blog) use ($term) {
                $excerpt = is_string($blog->excerpt) ? trim($blog->excerpt) : '';
                $body = is_string($blog->body) ? trim(strip_tags($blog->body)) : '';
                $author = $blog->user?->nickname ?? $blog->user?->name ?? null;
                $description = $excerpt !== ''
                    ? $excerpt
                    : ($body !== '' ? Str::limit($body, 160) : null);

                $highlights = [
                    'title' => $this->highlightText($blog->title, $term),
                    'description' => $this->firstHighlight([
                        $excerpt,
                        $body,
                        $author ? 'By ' . $author : null,
                    ], $term),
                ];

                return [
                    'id' => $blog->id,
                    'title' => $blog->title,
                    'description' => $description !== null ? Str::limit($description, 120) : null,
                    'highlight' => $highlights,
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
        $query = ForumThread::query()
            ->select([
                'forum_threads.id',
                'forum_threads.forum_board_id',
                'forum_threads.title',
                'forum_threads.slug',
                'forum_threads.excerpt',
                'forum_threads.last_posted_at',
                'forum_threads.created_at',
                'forum_threads.user_id',
            ])
            ->leftJoin('users', 'users.id', '=', 'forum_threads.user_id')
            ->where('forum_threads.is_published', true)
            ->when($this->supportsFullText(), function ($query) use ($term) {
                $query->whereRaw('MATCH(forum_threads.title, forum_threads.excerpt) AGAINST (? IN BOOLEAN MODE)', [$this->fullTextBooleanTerm($term)]);
            }, function ($query) use ($term) {
                $likeTerm = $this->likeTerm($term);

                $query->where(function ($query) use ($likeTerm) {
                    $query->where('forum_threads.title', 'like', $likeTerm)
                        ->orWhere('forum_threads.excerpt', 'like', $likeTerm)
                        ->orWhere('users.nickname', 'like', $likeTerm);
                });
            })
            ->when(
                ! $this->supportsFullText(),
                fn ($query) => $this->selectRelevance($query, [
                    'forum_threads.title' => 5,
                    'forum_threads.excerpt' => 3,
                    'users.nickname' => 2,
                ], $term),
            )
            ->when(
                $this->supportsFullText(),
                fn ($query) => $query->selectRaw(
                    'MATCH(forum_threads.title, forum_threads.excerpt) AGAINST (? IN BOOLEAN MODE) as relevance',
                    [$this->fullTextBooleanTerm($term)],
                ),
            )
            ->with(['board:id,slug,title'])
            ->with(['author:id,name,nickname'])
            ->orderByDesc('relevance')
            ->orderByDesc('forum_threads.last_posted_at')
            ->orderByDesc('forum_threads.created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = $paginator->getCollection()
            ->map(function (ForumThread $thread) use ($term) {
                $board = $thread->board;

                if ($board === null || ! $board->isVisible()) {
                    return null;
                }

                $excerpt = is_string($thread->excerpt) ? trim($thread->excerpt) : '';
                $author = $thread->author?->nickname ?? $thread->author?->name ?? null;
                $description = $excerpt !== '' ? $excerpt : ($author ? 'Started by ' . $author : null);
                $highlights = [
                    'title' => $this->highlightText($thread->title, $term),
                    'description' => $this->firstHighlight([
                        $excerpt,
                        $author ? 'Started by ' . $author : null,
                    ], $term),
                ];

                return [
                    'id' => $thread->id,
                    'title' => $thread->title,
                    'description' => $description !== null ? Str::limit($description, 120) : null,
                    'highlight' => $highlights,
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
        $query = Faq::query()
            ->select(['faqs.id', 'faqs.faq_category_id', 'faqs.question', 'faqs.answer'])
            ->leftJoin('faq_categories', 'faq_categories.id', '=', 'faqs.faq_category_id')
            ->where('faqs.published', true)
            ->when($this->supportsFullText(), function ($query) use ($term) {
                $query->whereRaw('MATCH(faqs.question, faqs.answer) AGAINST (? IN BOOLEAN MODE)', [$this->fullTextBooleanTerm($term)]);
            }, function ($query) use ($term) {
                $likeTerm = $this->likeTerm($term);

                $query->where(function ($query) use ($likeTerm) {
                    $query->where('faqs.question', 'like', $likeTerm)
                        ->orWhere('faqs.answer', 'like', $likeTerm)
                        ->orWhere('faq_categories.name', 'like', $likeTerm);
                });
            })
            ->when(
                ! $this->supportsFullText(),
                fn ($query) => $this->selectRelevance($query, [
                    'faqs.question' => 5,
                    'faqs.answer' => 3,
                    'faq_categories.name' => 2,
                ], $term),
            )
            ->when(
                $this->supportsFullText(),
                fn ($query) => $query->selectRaw(
                    'MATCH(faqs.question, faqs.answer) AGAINST (? IN BOOLEAN MODE) as relevance',
                    [$this->fullTextBooleanTerm($term)],
                ),
            )
            ->orderByDesc('relevance')
            ->orderBy('faqs.order')
            ->orderBy('faqs.question');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = $paginator->getCollection()
            ->map(function (Faq $faq) use ($term) {
                $answer = is_string($faq->answer) ? trim(strip_tags($faq->answer)) : '';

                $params = ['faqs_search' => $term];

                if ($faq->faq_category_id) {
                    $params['faq_category_id'] = $faq->faq_category_id;
                }

                $description = $answer !== '' ? Str::limit($answer, 160) : null;
                $highlights = [
                    'title' => $this->highlightText($faq->question, $term),
                    'description' => $this->firstHighlight([$answer], $term),
                ];

                return [
                    'id' => $faq->id,
                    'title' => $faq->question,
                    'description' => $description !== null ? Str::limit($description, 120) : null,
                    'highlight' => $highlights,
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

    private function groupToSection(string $group): ?string
    {
        return match ($group) {
            'blogs' => WebsiteSections::BLOG,
            'forum_threads' => WebsiteSections::FORUM,
            'faqs' => WebsiteSections::SUPPORT,
            default => null,
        };
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

    /**
     * @param  array<string, int>  $columns
     */
    private function selectRelevance($query, array $columns, string $term): void
    {
        [$expression, $bindings] = $this->relevanceExpression($columns, $term);

        $query->selectRaw($expression, $bindings);
    }

    /**
     * @param  array<string, int>  $columns
     * @return array{0: string, 1: array<int, string>}
     */
    private function relevanceExpression(array $columns, string $term): array
    {
        if ($columns === []) {
            return ['0 as relevance', []];
        }

        $bindings = [];
        $clauses = [];
        $needle = $this->likeTerm($term);

        foreach ($columns as $column => $weight) {
            $bindings[] = $needle;
            $clauses[] = "CASE WHEN LOWER({$column}) LIKE LOWER(?) THEN {$weight} ELSE 0 END";
        }

        $expression = implode(' + ', $clauses);

        return [$expression . ' as relevance', $bindings];
    }

    private function highlightText(?string $text, string $term, ?int $limit = null): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = trim(strip_tags($text));

        if ($normalized === '') {
            return null;
        }

        $limit = $limit ?? (int) config('search.highlight.context_chars', 80);
        $snippet = $this->snippet($normalized, $term, $limit);

        if ($snippet === null) {
            return null;
        }

        return $this->applyHighlight($snippet, $term);
    }

    /**
     * @param  array<int, string|null>  $candidates
     */
    private function firstHighlight(array $candidates, string $term): ?string
    {
        foreach ($candidates as $candidate) {
            $highlight = $this->highlightText($candidate, $term);

            if ($highlight !== null) {
                return $highlight;
            }
        }

        return null;
    }

    private function snippet(string $text, string $term, int $limit): ?string
    {
        $normalizedText = Str::lower($text);
        $normalizedTerm = Str::lower($term);
        $position = mb_strpos($normalizedText, $normalizedTerm);

        if ($position === false) {
            return null;
        }

        $start = max(0, $position - (int) ($limit / 2));
        $snippet = mb_substr($text, $start, $limit);

        if ($start > 0) {
            $snippet = '…' . ltrim($snippet);
        }

        if ($start + mb_strlen($snippet) < mb_strlen($text)) {
            $snippet = rtrim($snippet) . '…';
        }

        return $snippet;
    }

    private function applyHighlight(string $text, string $term): string
    {
        $escaped = e($text);
        $pattern = '/' . preg_quote($term, '/') . '/i';

        return (string) preg_replace($pattern, '<mark>$0</mark>', $escaped);
    }

    private function likeTerm(string $term): string
    {
        return '%' . Str::lower($term) . '%';
    }

    private function supportsFullText(): bool
    {
        return config('database.default') === 'mysql' && config('search.driver') === 'mysql_fulltext';
    }

    private function fullTextBooleanTerm(string $term): string
    {
        $keywords = Collection::make(preg_split('/\s+/', trim($term)))
            ->filter()
            ->map(fn ($value) => '+' . $value . '*')
            ->implode(' ');

        return $keywords !== '' ? $keywords : $term;
    }
}
