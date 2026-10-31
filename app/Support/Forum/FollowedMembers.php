<?php

namespace App\Support\Forum;

use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\MemberFollow;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who a member follows, and the public forum posts those people have written (docs/member-follows.md).
 */
class FollowedMembers
{
    public function __construct(private readonly IgnoredMembers $ignored) {}

    /**
     * @return list<int>
     */
    public function ids(User $viewer): array
    {
        $ids = [];

        foreach (MemberFollow::query()->where('user_id', $viewer->id)->pluck('followed_user_id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    public function following(User $viewer, User $member): bool
    {
        return MemberFollow::query()
            ->where('user_id', $viewer->id)
            ->where('followed_user_id', $member->id)
            ->exists();
    }

    public function canFollow(User $viewer, User $member): bool
    {
        return $viewer->id !== $member->id && ! $member->is_banned;
    }

    /**
     * Everyone this member follows, including someone banned or ignored. The feed leaves those posts out.
     *
     * @return list<array{id: int, nickname: string, banned: bool}>
     */
    public function listing(User $viewer): array
    {
        $rows = [];

        $follows = MemberFollow::query()
            ->where('user_id', $viewer->id)
            ->with(['followed' => function ($query): void {
                $query->select('id', 'nickname', 'is_banned');
            }])
            ->orderBy('id')
            ->get();

        foreach ($follows as $follow) {
            $member = $follow->followed;

            if (! $member instanceof User) {
                continue;
            }

            $rows[] = [
                'id' => $member->id,
                'nickname' => (string) $member->nickname,
                'banned' => $member->is_banned,
            ];
        }

        return $rows;
    }

    /**
     * People who follow this author and should be told about a new post, apart from anyone already told another way.
     *
     * @param  array<int, true>  $except
     * @return Collection<int, User>
     */
    public function listeners(User $author, array $except): Collection
    {
        if ($author->is_banned) {
            return new Collection;
        }

        $quiet = array_flip($this->ignored->quietFor($author));
        $ids = [];

        foreach (MemberFollow::query()->where('followed_user_id', $author->id)->pluck('user_id') as $id) {
            $id = (int) $id;

            if ($id === $author->id || isset($quiet[$id]) || isset($except[$id])) {
                continue;
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_banned', false)
            ->orderBy('id')
            ->get();
    }

    /**
     * Public posts by people this member follows, newest first. Held posts, unpublished threads, ignored members and banned members are left out.
     *
     * @return LengthAwarePaginator<int, ForumPost>
     */
    public function feed(User $viewer): LengthAwarePaginator
    {
        $authors = $this->visibleAuthorIds($viewer);

        $query = ForumPost::query()
            ->with([
                'author:id,nickname,is_banned',
                'thread:id,forum_board_id,title,slug',
                'thread.board:id,title,slug',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($authors === []) {
            $query->whereIn('user_id', []);
        } else {
            $query->whereIn('user_id', $authors)
                ->whereHas('thread', function (Builder $query): void {
                    $query->where('is_published', true)->whereNull('held_at');
                });
        }

        return $query->paginate(15);
    }

    /**
     * @return array{id: int, excerpt: string|null, url: string, thread: string, board: string, author: array{id: int, nickname: string}, created_at: string|null}|null
     */
    public function present(ForumPost $post, DateFormatter $formatter, ?PostPages $pages = null): ?array
    {
        $author = $post->author;
        $thread = $post->thread;
        $board = $thread?->board;

        if (! $author instanceof User || $author->is_banned || ! $thread instanceof ForumThread || ! $board instanceof ForumBoard) {
            return null;
        }

        return [
            'id' => $post->id,
            'excerpt' => $this->excerpt($post->body),
            'url' => ($pages ?? PostPages::of([$post]))->address($post, $board, $thread),
            'thread' => $thread->title,
            'board' => $board->title,
            'author' => [
                'id' => $author->id,
                'nickname' => (string) $author->nickname,
            ],
            'created_at' => $formatter->date($post->created_at),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function follow(User $viewer, User $member): void
    {
        if ($viewer->id === $member->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot follow yourself.',
            ]);
        }

        if ($member->is_banned || ! $this->canFollow($viewer, $member)) {
            throw ValidationException::withMessages([
                'user' => 'That member cannot be followed.',
            ]);
        }

        MemberFollow::query()->firstOrCreate([
            'user_id' => $viewer->id,
            'followed_user_id' => $member->id,
        ]);
    }

    public function forget(User $viewer, User $member): void
    {
        MemberFollow::query()
            ->where('user_id', $viewer->id)
            ->where('followed_user_id', $member->id)
            ->delete();
    }

    /**
     * Followed members whose posts belong on the feed.
     *
     * @return list<int>
     */
    private function visibleAuthorIds(User $viewer): array
    {
        $quiet = array_flip($this->ignored->ids($viewer));
        $ids = [];

        foreach ($this->ids($viewer) as $id) {
            if (! isset($quiet[$id])) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $banned = [];

        foreach (User::query()->whereIn('id', $ids)->where('is_banned', true)->pluck('id') as $id) {
            $banned[(int) $id] = true;
        }

        $visible = [];

        foreach ($ids as $id) {
            if (! isset($banned[$id])) {
                $visible[] = $id;
            }
        }

        return $visible;
    }

    private function excerpt(?string $value): ?string
    {
        $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($text === '') {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::limit($text, 180);
    }
}
