<?php

namespace App\Models;

use App\Casts\SanitizedHtml;
use App\Support\Forum\ForumAttachments;
use App\Support\Forum\ThreadSolutions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reply in a forum thread (the first post of a thread is one too).
 *
 * A reply that automatic moderation has caught is *held* (`held_at`, docs/automod.md): out of sight of everybody but its author and
 * the moderators until one approves it. So that nothing that lists, counts or searches replies has to remember that, a global scope
 * leaves held replies out of every query; what needs them asks `withoutGlobalScope(ForumPost::VISIBLE)`.
 */
class ForumPost extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** The name of the global scope that leaves held replies out. */
    public const VISIBLE = 'visible';

    protected $fillable = [
        'forum_thread_id',
        'user_id',
        'body',
        'edited_at',
    ];

    protected $casts = [
        'body' => SanitizedHtml::class.':forum',
        'edited_at' => 'datetime',
        'held_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(self::VISIBLE, fn (Builder $query) => $query->whereNull($query->getModel()->qualifyColumn('held_at')));

        // A soft delete does not clear the thread's foreign key. A query delete skips this and has to call forget() itself.
        static::deleting(function (ForumPost $post): void {
            app(ThreadSolutions::class)->forget([$post->id]);
            app(ForumAttachments::class)->forget([$post->id]);
        });
    }

    public function isHeld(): bool
    {
        return $this->held_at !== null;
    }

    /**
     * @return BelongsTo<ForumThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ForumThread::class, 'forum_thread_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'forum_post_mentions', 'forum_post_id', 'mentioned_user_id')
            ->withTimestamps();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ForumPostRevision::class, 'forum_post_id');
    }

    /**
     * @return HasMany<ForumAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ForumAttachment::class)->orderBy('id');
    }
}
