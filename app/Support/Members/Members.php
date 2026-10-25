<?php

namespace App\Support\Members;

use App\Models\BlogComment;
use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use App\Support\WebsiteSections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * The public member directory and a member's public profile (docs/members.md).
 *
 * The address is the account's id. A nickname can change, and it can contain characters that do not belong in an address.
 * What is shown is only what is already public: never the email address, and nothing of a banned account.
 */
class Members
{
    public const PAGE_SIZE = 24;

    private const RECENT = 5;

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function directory(string $search, string $sort): LengthAwarePaginator
    {
        $query = User::query()
            ->where('is_banned', false)
            ->select(['id', 'nickname', 'avatar_url', 'profile_bio', 'reputation_points', 'created_at']);

        if ($search !== '') {
            $query->whereLike('nickname', '%'.$search.'%');
        }

        match ($sort) {
            'name' => $query->orderBy('nickname')->orderBy('id'),
            'reputation' => $query->orderByDesc('reputation_points')->orderBy('nickname')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return $query->paginate(self::PAGE_SIZE)->withQueryString();
    }

    /**
     * One card in the directory.
     *
     * @return array{id: int, nickname: string, url: string, avatar_url: string|null, bio: string|null, reputation: int, joined_at: string|null}
     */
    public function card(User $member, DateFormatter $formatter): array
    {
        return [
            'id' => $member->id,
            'nickname' => (string) $member->nickname,
            'url' => route('members.show', $member),
            'avatar_url' => $this->publicHttpUrl($member->avatar_url),
            'bio' => $this->excerpt($member->profile_bio, 160),
            'reputation' => (int) $member->reputation_points,
            'joined_at' => $formatter->date($member->created_at),
        ];
    }

    /**
     * The public profile. The caller has already refused a banned account.
     *
     * @return array{
     *     id: int,
     *     nickname: string,
     *     avatar_url: string|null,
     *     bio: string|null,
     *     signature: string|null,
     *     role: string|null,
     *     reputation: int,
     *     joined_at: string|null,
     *     badges: list<array{id: int, name: string, description: string|null}>,
     *     links: list<array{label: string, url: string}>,
     *     activity: array{
     *         forum: array{thread_count: int, reply_count: int, threads: list<array{id: int, title: string, url: string, board: string, created_at: string|null}>, replies: list<array{id: int, excerpt: string|null, url: string, thread: string, created_at: string|null}>}|null,
     *         blog: array{comment_count: int}|null
     *     }
     * }
     */
    public function profile(User $member, DateFormatter $formatter): array
    {
        $member->loadMissing(['badges' => function ($query): void {
            $query->select('badges.id', 'badges.name', 'badges.description');
        }]);

        $forum = WebsiteSections::isEnabled(WebsiteSections::FORUM);
        $blog = WebsiteSections::isEnabled(WebsiteSections::BLOG);

        return [
            'id' => $member->id,
            'nickname' => (string) $member->nickname,
            'avatar_url' => $this->publicHttpUrl($member->avatar_url),
            'bio' => $this->text($member->profile_bio),
            'signature' => $forum ? $this->text($member->forum_signature) : null,
            'role' => $this->role($member),
            'reputation' => (int) $member->reputation_points,
            'joined_at' => $formatter->date($member->created_at),
            'badges' => $this->badges($member),
            'links' => $this->links($member),
            'activity' => [
                'forum' => $forum ? [
                    'thread_count' => $this->threads($member)->count(),
                    'reply_count' => $this->replies($member)->count(),
                    'threads' => $this->recentThreads($member, $formatter),
                    'replies' => $this->recentReplies($member, $formatter),
                ] : null,
                'blog' => $blog ? [
                    'comment_count' => $this->comments($member)->count(),
                ] : null,
            ],
        ];
    }

    /**
     * @return Builder<ForumThread>
     */
    private function threads(User $member): Builder
    {
        return ForumThread::query()
            ->where('user_id', $member->id)
            ->where('is_published', true)
            ->whereNull('held_at');
    }

    /**
     * Replies in other people's published threads. A reply in a thread they started belongs to that thread.
     *
     * @return Builder<ForumPost>
     */
    private function replies(User $member): Builder
    {
        return ForumPost::query()
            ->where('user_id', $member->id)
            ->whereHas('thread', function (Builder $query) use ($member): void {
                $query->where('is_published', true)
                    ->whereNull('held_at')
                    ->where('user_id', '!=', $member->id);
            });
    }

    /**
     * @return Builder<BlogComment>
     */
    private function comments(User $member): Builder
    {
        return BlogComment::query()
            ->where('user_id', $member->id)
            ->where('status', BlogComment::STATUS_APPROVED)
            ->whereHas('blog', fn (Builder $query) => $query->where('status', 'published'));
    }

    /**
     * @return list<array{id: int, title: string, url: string, board: string, created_at: string|null}>
     */
    private function recentThreads(User $member, DateFormatter $formatter): array
    {
        $threads = [];

        foreach ($this->threads($member)->with('board:id,title,slug')->latest('created_at')->limit(self::RECENT)->get(['id', 'forum_board_id', 'title', 'slug', 'created_at']) as $thread) {
            $board = $thread->board;

            if (! $board instanceof ForumBoard) {
                continue;
            }

            $threads[] = [
                'id' => $thread->id,
                'title' => $thread->title,
                'url' => route('forum.threads.show', [$board, $thread]),
                'board' => $board->title,
                'created_at' => $formatter->date($thread->created_at),
            ];
        }

        return $threads;
    }

    /**
     * @return list<array{id: int, excerpt: string|null, url: string, thread: string, created_at: string|null}>
     */
    private function recentReplies(User $member, DateFormatter $formatter): array
    {
        $replies = [];

        $posts = $this->replies($member)
            ->with(['thread:id,forum_board_id,title,slug', 'thread.board:id,title,slug'])
            ->latest('created_at')
            ->limit(self::RECENT)
            ->get(['id', 'forum_thread_id', 'body', 'created_at']);

        foreach ($posts as $post) {
            $thread = $post->thread;
            $board = $thread?->board;

            if ($thread === null || ! $board instanceof ForumBoard) {
                continue;
            }

            $replies[] = [
                'id' => $post->id,
                'excerpt' => $this->excerpt($post->body, 180),
                'url' => route('forum.threads.show', [$board, $thread]),
                'thread' => $thread->title,
                'created_at' => $formatter->date($post->created_at),
            ];
        }

        return $replies;
    }

    /**
     * @return list<array{id: int, name: string, description: string|null}>
     */
    private function badges(User $member): array
    {
        $badges = [];

        foreach ($member->badges as $badge) {
            $badges[] = [
                'id' => $badge->id,
                'name' => $badge->name,
                'description' => $badge->description,
            ];
        }

        return $badges;
    }

    private function role(User $member): ?string
    {
        $role = $member->getRoleNames()->first();

        return is_string($role) && $role !== '' ? Str::headline($role) : null;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function links(User $member): array
    {
        if (! is_array($member->social_links)) {
            return [];
        }

        $links = [];

        foreach ($member->social_links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $url = $this->publicHttpUrl(isset($link['url']) && is_string($link['url']) ? $link['url'] : null);

            if ($url === null) {
                continue;
            }

            $label = $this->text(isset($link['label']) && is_string($link['label']) ? $link['label'] : null);
            $host = parse_url($url, PHP_URL_HOST);

            $links[] = [
                'label' => $label !== null ? Str::limit($label, 80, '') : (is_string($host) ? $host : $url),
                'url' => $url,
            ];
        }

        return $links;
    }

    /**
     * An address a browser may open. Anything else (a script, a data address, a login embedded in the address) is dropped.
     */
    private function publicHttpUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    private function text(?string $value): ?string
    {
        $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    private function excerpt(?string $value, int $limit): ?string
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::limit($text, $limit);
    }
}
