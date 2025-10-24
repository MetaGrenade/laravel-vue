<?php

namespace App\Support\Forum;

use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class ForumIndexCache
{
    private const CACHE_KEY_PREFIX = 'forum:index:';

    public function categories(): Collection
    {
        return $this->remember('categories', function () {
            return ForumCategory::query()
                ->with(['boards' => function ($query) {
                    $query->withCount([
                        'publishedThreads as threads_count',
                        'posts as posts_count' => function ($postQuery) {
                            $postQuery->where('forum_threads.is_published', true);
                        },
                    ])->with(['latestThread' => function ($threadQuery) {
                        $threadQuery->with(['author:id,nickname', 'latestPost.author:id,nickname'])
                            ->withCount('posts');
                    }]);
                }])
                ->orderBy('position')
                ->get();
        });
    }

    public function trendingThreads(): Collection
    {
        return $this->remember('trending-threads', function () {
            return ForumThread::query()
                ->where('is_published', true)
                ->with(['board:id,slug,title,forum_category_id', 'board.category:id,slug,title', 'author:id,nickname'])
                ->withCount('posts')
                ->orderByDesc('is_pinned')
                ->orderByDesc('views')
                ->orderByDesc('last_posted_at')
                ->limit(5)
                ->get();
        });
    }

    public function latestPosts(): Collection
    {
        return $this->remember('latest-posts', function () {
            return ForumPost::query()
                ->whereHas('thread', function ($query) {
                    $query->where('is_published', true);
                })
                ->with([
                    'thread:id,slug,title,forum_board_id',
                    'thread.board:id,slug,title,forum_category_id',
                    'author:id,nickname,created_at',
                ])
                ->latest()
                ->limit(5)
                ->get();
        });
    }

    public function clear(): void
    {
        Cache::forget($this->key('categories'));
        Cache::forget($this->key('trending-threads'));
        Cache::forget($this->key('latest-posts'));
    }

    private function remember(string $suffix, callable $callback): Collection
    {
        $ttl = config('forum.index_cache_ttl', 300);

        return Cache::remember($this->key($suffix), $ttl, $callback);
    }

    private function key(string $suffix): string
    {
        return self::CACHE_KEY_PREFIX . $suffix;
    }
}
