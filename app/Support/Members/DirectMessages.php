<?php

namespace App\Support\Members;

use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use App\Support\Forum\IgnoredMembers;
use App\Support\Localization\DateFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Private messages between two members (docs/direct-messages.md).
 */
class DirectMessages
{
    public const TRANSCRIPT_PER_PAGE = 20;

    public function __construct(private readonly IgnoredMembers $ignored) {}

    /**
     * Why a message cannot be sent, or null when it can.
     *
     * `ignoring` is the sender's own choice. `unavailable` covers a ban and an ignore
     * that belongs to the other person, which is not told apart.
     */
    public function block(User $from, User $to): ?string
    {
        if ($from->id === $to->id) {
            return 'self';
        }

        if ($from->is_banned || $to->is_banned) {
            return 'unavailable';
        }

        if ($this->ignored->ignoring($from, $to)) {
            return 'ignoring';
        }

        if ($this->ignored->ignoring($to, $from)) {
            return 'unavailable';
        }

        return null;
    }

    public function between(User $a, User $b): ?DirectConversation
    {
        return DirectConversation::query()->where($this->pair($a, $b))->first();
    }

    /**
     * @return LengthAwarePaginator<int, DirectConversation>
     */
    public function inbox(User $viewer): LengthAwarePaginator
    {
        return DirectConversation::query()
            ->where(function (Builder $query) use ($viewer): void {
                $query->where('user_low_id', $viewer->id)
                    ->orWhere('user_high_id', $viewer->id);
            })
            ->with([
                'low:id,nickname,is_banned',
                'high:id,nickname,is_banned',
                'latestMessage',
            ])
            ->withCount([
                'messages as unread_count' => function (Builder $query) use ($viewer): void {
                    $query->where('user_id', '!=', $viewer->id)->whereNull('read_at');
                },
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * @return LengthAwarePaginator<int, DirectMessage>
     */
    public function transcript(User $viewer, User $other): LengthAwarePaginator
    {
        $conversation = $this->between($viewer, $other);

        if ($conversation === null) {
            return DirectMessage::query()->whereIn('direct_messages.id', [])->orderBy('id')->paginate(self::TRANSCRIPT_PER_PAGE);
        }

        return $conversation->messages()->orderBy('id')->paginate(self::TRANSCRIPT_PER_PAGE);
    }

    public function markRead(User $viewer, DirectConversation $conversation): void
    {
        if ($conversation->user_low_id !== $viewer->id && $conversation->user_high_id !== $viewer->id) {
            return;
        }

        $conversation->messages()
            ->where('user_id', '!=', $viewer->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function send(User $from, User $to, string $body): DirectMessage
    {
        $block = $this->block($from, $to);

        if ($block === 'self') {
            throw ValidationException::withMessages([
                'user' => 'You cannot message yourself.',
            ]);
        }

        if ($block === 'ignoring') {
            throw ValidationException::withMessages([
                'user' => 'You are ignoring '.$to->nickname.'.',
            ]);
        }

        if ($block === 'unavailable') {
            throw ValidationException::withMessages([
                'user' => 'That member cannot be messaged.',
            ]);
        }

        $text = $this->plain($body);

        if ($text === '') {
            throw ValidationException::withMessages([
                'body' => 'A message cannot be empty.',
            ]);
        }

        $message = DB::transaction(function () use ($from, $to, $text): DirectMessage {
            $conversation = DirectConversation::query()->firstOrCreate($this->pair($from, $to));

            $message = $conversation->messages()->create([
                'user_id' => $from->id,
                'body' => $text,
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at ?? now(),
            ])->save();

            return $message;
        });

        $to->notifyThroughPreferences(
            new DirectMessageReceived($from, $message),
            'messages',
            ['database', 'mail', 'push'],
        );

        return $message;
    }

    /**
     * @param  LengthAwarePaginator<int, DirectConversation>  $page
     * @return list<array{member: array{id: int, nickname: string, banned: bool}, excerpt: string|null, unread: bool, url: string, created_at: string|null}>
     */
    public function presentInbox(LengthAwarePaginator $page, User $viewer, DateFormatter $formatter): array
    {
        $rows = [];

        foreach ($page->getCollection() as $conversation) {
            $other = $this->other($conversation, $viewer);

            if ($other === null) {
                continue;
            }

            $latest = $conversation->latestMessage;

            $rows[] = [
                'member' => [
                    'id' => $other->id,
                    'nickname' => (string) $other->nickname,
                    'banned' => (bool) $other->is_banned,
                ],
                'excerpt' => $latest instanceof DirectMessage ? $this->excerpt($latest->body) : null,
                'unread' => (int) $conversation->unread_count > 0,
                'url' => route('messages.show', $other),
                'created_at' => $formatter->dayDateTime($conversation->last_message_at),
            ];
        }

        return $rows;
    }

    /**
     * @param  LengthAwarePaginator<int, DirectMessage>  $page
     * @return list<array{id: int, body: string, mine: bool, created_at: string|null}>
     */
    public function presentTranscript(LengthAwarePaginator $page, User $viewer, DateFormatter $formatter): array
    {
        $rows = [];

        foreach ($page->getCollection() as $message) {
            $rows[] = [
                'id' => $message->id,
                'body' => $message->body,
                'mine' => $message->user_id === $viewer->id,
                'created_at' => $formatter->dayDateTime($message->created_at),
            ];
        }

        return $rows;
    }

    public function plain(string $body): string
    {
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim($text);
    }

    /**
     * @return array{user_low_id: int, user_high_id: int}
     */
    private function pair(User $a, User $b): array
    {
        $low = min($a->id, $b->id);
        $high = max($a->id, $b->id);

        return [
            'user_low_id' => $low,
            'user_high_id' => $high,
        ];
    }

    private function other(DirectConversation $conversation, User $viewer): ?User
    {
        $low = $conversation->low;
        $high = $conversation->high;

        if ($low === null || $high === null) {
            return null;
        }

        return $low->id === $viewer->id ? $high : $low;
    }

    private function excerpt(string $body): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $body) ?? '');

        if ($text === '') {
            return null;
        }

        return Str::limit($text, 140);
    }
}
