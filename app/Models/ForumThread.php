<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A forum thread. One that automatic moderation has caught is *held* (`held_at`, docs/automod.md): it is not published, so it is
 * out of sight of everybody but the moderators, until one approves it.
 */
class ForumThread extends Model
{
    use HasFactory;

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
    }

    public function isHeld(): bool
    {
        return $this->held_at !== null;
    }

    protected $fillable = [
        'forum_board_id',
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
}
