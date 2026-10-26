<?php

namespace App\Support\Forum;

use App\Models\ForumBoard;
use App\Models\ForumPrefix;
use App\Models\ForumThread;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The public Atom feed of published threads (docs/forum-rss.md). The same shape as the blog feed.
 */
class ForumFeed
{
    public const LATEST = 20;

    public function response(?ForumBoard $board = null): Response
    {
        $query = ForumThread::query()->where('is_published', true);

        if ($board instanceof ForumBoard) {
            $query->where('forum_board_id', $board->id);
        }

        $threads = $query
            ->with([
                'board:id,title,slug',
                'prefix:id,name',
            ])
            ->orderByDesc('last_posted_at')
            ->orderByDesc('id')
            ->limit(self::LATEST)
            ->get([
                'id',
                'forum_board_id',
                'forum_prefix_id',
                'title',
                'slug',
                'excerpt',
                'created_at',
                'updated_at',
                'last_posted_at',
            ]);

        $entries = [];

        foreach ($threads as $thread) {
            $listedOn = $thread->board instanceof ForumBoard ? $thread->board : $board;

            if (! $listedOn instanceof ForumBoard) {
                continue;
            }

            $publishedAt = $thread->created_at ?? Carbon::now();
            $updatedAt = $thread->last_posted_at ?? $thread->updated_at ?? $publishedAt;
            $excerpt = trim((string) $thread->excerpt);

            $entries[] = [
                'title' => $this->title($thread),
                'link' => route('forum.threads.show', [$listedOn, $thread]),
                'excerpt' => $excerpt === '' ? null : $excerpt,
                'published_at' => $publishedAt->toAtomString(),
                'updated_at' => $updatedAt->toAtomString(),
                'category' => [
                    'term' => $listedOn->slug,
                    'label' => $listedOn->title,
                ],
            ];
        }

        $latest = $threads->first();
        $feedUpdatedAt = $latest instanceof ForumThread
            ? ($latest->last_posted_at ?? $latest->updated_at ?? $latest->created_at ?? Carbon::now())
            : Carbon::now();

        $xml = view('feeds.forum', [
            'title' => $board instanceof ForumBoard
                ? config('app.name').' Forum: '.$board->title
                : config('app.name').' Forum',
            'homeUrl' => $board instanceof ForumBoard
                ? route('forum.boards.show', $board)
                : route('forum.index'),
            'selfUrl' => $board instanceof ForumBoard
                ? route('forum.boards.feed', $board)
                : route('forum.feed'),
            'updatedAt' => $feedUpdatedAt->toAtomString(),
            'entries' => $entries,
        ])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/atom+xml; charset=UTF-8',
        ]);
    }

    private function title(ForumThread $thread): string
    {
        $prefix = $thread->prefix;

        if ($prefix instanceof ForumPrefix && trim($prefix->name) !== '') {
            return $prefix->name.': '.$thread->title;
        }

        return $thread->title;
    }
}
