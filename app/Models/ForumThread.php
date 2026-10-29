<?php

namespace App\Models;

use App\Models\Concerns\HasSearchDocument;
use App\Support\Forum\ForumAttachments;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A forum thread. One that automatic moderation has caught is *held* (`held_at`, docs/automod.md): it is not published, so it is
 * out of sight of everybody but the moderators, until one approves it.
 *
 * @property int|null $solved_post_id
 */
class ForumThread extends Model
{
    use HasFactory;
    use HasSearchDocument;

    protected static function booted(): void
    {
        // A thread that is published by any means (the moderators' publish button as much as the review queue) is no longer held.
        static::saving(function (ForumThread $thread) {
            if ($thread->is_published && $thread->held_at !== null) {
                $thread->held_at = null;
                $thread->held_reason = null;
                $thread->held_by_rule_id = null;
            }
        });

        // Deleting the thread cascades its posts in the database, which does not run their model events, so the files go first.
        static::deleting(function (ForumThread $thread): void {
            $ids = [];

            foreach (
                ForumPost::query()
                    ->withTrashed()
                    ->withoutGlobalScope(ForumPost::VISIBLE)
                    ->where('forum_thread_id', $thread->id)
                    ->pluck('id') as $id
            ) {
                $ids[] = (int) $id;
            }

            app(ForumAttachments::class)->forget($ids);
        });
    }

    public function isHeld(): bool
    {
        return $this->held_at !== null;
    }

    protected $fillable = [
        'forum_board_id',
        'forum_prefix_id',
        'user_id',
        'title',
        'slug',
        'excerpt',
        'is_locked',
        'is_pinned',
        'is_published',
        'views',
        'last_posted_at',
        'last_post_user_id',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'is_pinned' => 'boolean',
        'is_published' => 'boolean',
        'last_posted_at' => 'datetime',
        'last_read_at' => 'datetime',
        'held_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<ForumPrefix, $this>
     */
    public function prefix(): BelongsTo
    {
        return $this->belongsTo(ForumPrefix::class, 'forum_prefix_id');
    }

    /**
     * The reply that answers this thread, when one has been chosen (docs/forum-solutions.md).
     *
     * @return BelongsTo<ForumPost, $this>
     */
    public function solution(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'solved_post_id');
    }

    /**
     * @return BelongsTo<ForumBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(ForumBoard::class, 'forum_board_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lastPostAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_post_user_id');
    }

    /**
     * @return HasMany<ForumPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class);
    }

    public function latestPost(): HasOne
    {
        return $this->hasOne(ForumPost::class)->latestOfMany();
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ForumThreadRead::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ForumThreadSubscription::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'forum_thread_subscriptions')->withTimestamps();
    }

    public function isSubscribedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->subscriptions()->where('user_id', $user->id)->exists();
    }

    public function searchableAs(): string
    {
        return 'forum_threads';
    }

    /**
     * A published thread. Boards are public, so there is no permission to
     * apply here; a board that later requires one is excluded in this method.
     * A held thread is not published.
     */
    public function shouldBeSearchable(): bool
    {
        return (bool) $this->is_published;
    }

    /**
     * @return array<string, string>
     */
    public function toSearchableArray(): array
    {
        return $this->searchDocument([
            'title' => (string) $this->title,
            'excerpt' => SearchText::plain($this->excerpt),
            'replies' => $this->visibleReplyText(),
            'author' => $this->searchNickname($this->user_id),
        ]);
    }

    /**
     * Visible replies only. A held reply is left out by the model's scope,
     * and a deleted one is left out with it. The text stops at the document limit.
     */
    private function visibleReplyText(): string
    {
        $remaining = max(1, (int) config('search.documents.max_characters', 8000));
        $parts = [];

        $bodies = ForumPost::query()
            ->where('forum_thread_id', $this->id)
            ->orderBy('id')
            ->limit(40)
            ->pluck('body');

        foreach ($bodies as $body) {
            if ($remaining < 1) {
                break;
            }

            $plain = SearchText::plain($body, $remaining);

            if ($plain === '') {
                continue;
            }

            $parts[] = $plain;
            $remaining -= mb_strlen($plain) + 1;
        }

        return implode(' ', $parts);
    }
}
