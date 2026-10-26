<?php

namespace App\Support\Reputation;

use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ReputationEvent;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * The public leaderboard and the recent public reputation events (docs/leaderboard.md).
 *
 * The list is the same for every viewer. A banned account is left out, and an event is left out unless its post is still public.
 */
class Leaderboard
{
    public const STANDINGS = 20;

    public const ACTIVITY = 20;

    /**
     * @return list<array{rank: int, id: int, nickname: string, url: string, avatar_url: string|null, reputation: int, badges: list<array{id: int, name: string}>}>
     */
    public function standings(): array
    {
        $members = User::query()
            ->where('is_banned', false)
            ->where('reputation_points', '>', 0)
            ->with(['badges' => function ($query): void {
                $query->select('badges.id', 'badges.name')->orderBy('badges.points_required')->orderBy('badges.id');
            }])
            ->orderByDesc('reputation_points')
            ->orderBy('nickname')
            ->orderBy('id')
            ->limit(self::STANDINGS)
            ->get(['id', 'nickname', 'avatar_url', 'reputation_points']);

        $rows = [];

        foreach ($members as $member) {
            $badges = [];

            foreach ($member->badges as $badge) {
                $badges[] = [
                    'id' => $badge->id,
                    'name' => $badge->name,
                ];
            }

            $rows[] = [
                'rank' => count($rows) + 1,
                'id' => $member->id,
                'nickname' => (string) $member->nickname,
                'url' => route('members.show', $member),
                'avatar_url' => $this->publicHttpUrl($member->avatar_url),
                'reputation' => (int) $member->reputation_points,
                'badges' => $badges,
            ];
        }

        return $rows;
    }

    /**
     * The latest public events, newest first. The number on a line is what was awarded then. The words are the current name of that kind of event.
     *
     * @return list<array{id: int, points: int, label: string, at: string|null, member: array{id: int, nickname: string, url: string, avatar_url: string|null}, post: array{excerpt: string|null, url: string, thread: string}}>
     */
    public function activity(DateFormatter $formatter): array
    {
        $labels = $this->labels();

        if ($labels === []) {
            return [];
        }

        $events = ReputationEvent::query()
            ->whereIn('event', array_keys($labels))
            ->where('source_type', (new ForumPost)->getMorphClass())
            ->whereHas('user', fn (Builder $query) => $query->where('is_banned', false))
            ->whereHasMorph('source', [ForumPost::class], function (Builder $query): void {
                $query->whereHas('thread', function (Builder $thread): void {
                    $thread->where('is_published', true)->whereHas('board');
                });
            })
            ->with([
                'user:id,nickname,avatar_url,is_banned',
                'source' => function (Relation $relation): void {
                    if ($relation instanceof MorphTo) {
                        $relation->morphWith([
                            ForumPost::class => ['thread:id,forum_board_id,title,slug,is_published', 'thread.board:id,title,slug'],
                        ]);
                    }
                },
            ])
            ->latest('id')
            ->limit(self::ACTIVITY)
            ->get();

        $rows = [];

        foreach ($events as $event) {
            $row = $this->activityRow($event, $labels, $formatter);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $labels
     * @return array{id: int, points: int, label: string, at: string|null, member: array{id: int, nickname: string, url: string, avatar_url: string|null}, post: array{excerpt: string|null, url: string, thread: string}}|null
     */
    private function activityRow(ReputationEvent $event, array $labels, DateFormatter $formatter): ?array
    {
        $member = $event->user;
        $post = $event->source;
        $label = $labels[$event->event] ?? null;

        if (! $member instanceof User || $member->is_banned || ! $post instanceof ForumPost || ! is_string($label)) {
            return null;
        }

        $thread = $post->thread;
        $board = $thread?->board;

        if (! $thread instanceof ForumThread || ! $thread->is_published || ! $board instanceof ForumBoard) {
            return null;
        }

        return [
            'id' => $event->id,
            'points' => (int) $event->points,
            'label' => $label,
            'at' => $formatter->dayDateTime($event->created_at),
            'member' => [
                'id' => $member->id,
                'nickname' => (string) $member->nickname,
                'url' => route('members.show', $member),
                'avatar_url' => $this->publicHttpUrl($member->avatar_url),
            ],
            'post' => [
                'excerpt' => $this->excerpt($post->body),
                'url' => route('forum.threads.show', [$board, $thread]).'#post-'.$post->id,
                'thread' => $thread->title,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $configured = config('reputation.events');

        if (! is_array($configured)) {
            return [];
        }

        $labels = [];

        foreach ($configured as $key => $event) {
            if (! is_string($key) || ! is_array($event)) {
                continue;
            }

            $label = $event['label'] ?? null;

            if (is_string($label) && $label !== '') {
                $labels[$key] = $label;
            }
        }

        return $labels;
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

    /**
     * An address a browser may open. Anything else is dropped.
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
}
