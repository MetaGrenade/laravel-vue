<?php

namespace App\Support\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\ForumThread;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Global search against Scout/Meilisearch for the three page groups.
 * Visibility is already decided when documents are written; this only queries.
 */
final class MeilisearchGlobalSearch
{
    public function __construct(
        private readonly GlobalSearchService $presenter,
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int|null>}
     */
    public function searchBlogs(string $term, int $perPage, int $page): array
    {
        $paginator = Blog::search($term)
            ->query(fn ($query) => $query->with(['user:id,nickname']))
            ->paginate($perPage, 'page', $page);

        return $this->present($paginator, fn (Blog $blog) => $this->presenter->presentBlog($blog, $term));
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int|null>}
     */
    public function searchForumThreads(string $term, int $perPage, int $page): array
    {
        $paginator = ForumThread::search($term)
            ->query(fn ($query) => $query->with([
                'board:id,slug,title',
                'author:id,nickname',
            ]))
            ->paginate($perPage, 'page', $page);

        $items = collect($paginator->items())
            ->map(fn (ForumThread $thread) => $this->presenter->presentForumThread($thread, $term, includeReplyHighlights: true))
            ->filter()
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => $this->presenter->formatMeta($paginator),
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int|null>}
     */
    public function searchFaqs(string $term, int $perPage, int $page): array
    {
        $paginator = Faq::search($term)
            ->paginate($perPage, 'page', $page);

        return $this->present(
            $paginator,
            fn (Faq $faq) => $this->presenter->presentFaq($faq, $term, deepLinkWithoutSearch: true),
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $paginator
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int|null>}
     */
    private function present(LengthAwarePaginator $paginator, callable $map): array
    {
        $items = collect($paginator->items())
            ->map($map)
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => $this->presenter->formatMeta($paginator),
        ];
    }
}
