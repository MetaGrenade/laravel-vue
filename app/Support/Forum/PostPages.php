<?php

namespace App\Support\Forum;

use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The page of its thread a post is on, so that a link to a post opens the page that holds it (the thread page shows ten posts at a time
 * and does not look up `#post-id`). Held and deleted replies are not counted, as they are not shown, and the first page is the thread's
 * address with no page. A list of links asks for all its posts at once with {@see self::of()}: one query however many there are, where
 * counting the posts before each one asks a query per row.
 */
final class PostPages
{
    /** How many posts the thread page shows (ForumController::showThread). */
    public const PER_PAGE = 10;

    /**
     * @param  array<int, int>  $pages  By post id.
     */
    private function __construct(private readonly array $pages) {}

    /**
     * @param  iterable<mixed>  $posts  What is not a post is left out (the source of a reputation event may be another kind of model, or gone).
     */
    public static function of(iterable $posts): self
    {
        $ids = collect($posts)->filter(fn ($post) => $post instanceof ForumPost)->pluck('id')->unique()->values()->all();

        if ($ids === []) {
            return new self([]);
        }

        // How many posts of the thread come up to and include each one, oldest first and then by id.
        $positions = DB::table('forum_posts as target')
            ->whereIn('target.id', $ids)
            ->select('target.id')
            ->selectSub(
                ForumPost::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('forum_posts.forum_thread_id', 'target.forum_thread_id')
                    ->where(fn (Builder $query) => $query
                        ->whereColumn('forum_posts.created_at', '<', 'target.created_at')
                        ->orWhere(fn (Builder $same) => $same
                            ->whereColumn('forum_posts.created_at', '=', 'target.created_at')
                            ->whereColumn('forum_posts.id', '<=', 'target.id'))),
                'posts_up_to',
            )
            ->pluck('posts_up_to', 'id');

        $pages = [];

        foreach ($ids as $id) {
            $pages[$id] = (int) ceil(max((int) ($positions[$id] ?? 0), 1) / self::PER_PAGE);
        }

        return new self($pages);
    }

    public function page(ForumPost $post): int
    {
        return $this->pages[$post->id] ?? self::of([$post])->pages[$post->id] ?? 1;
    }

    /**
     * The address of the post: its thread's page, and the post on it.
     *
     * @param  ForumBoard|string  $board  The board, or its slug.
     * @param  ForumThread|string  $thread  The thread, or its slug.
     */
    public function address(ForumPost $post, ForumBoard|string $board, ForumThread|string $thread): string
    {
        $parameters = ['board' => $board, 'thread' => $thread];
        $page = $this->page($post);

        if ($page > 1) {
            $parameters['page'] = $page;
        }

        return route('forum.threads.show', $parameters).'#post-'.$post->id;
    }
}
