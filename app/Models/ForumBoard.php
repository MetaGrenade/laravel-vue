<?php

namespace App\Models;

use App\Support\Forum\ForumAttachments;
use App\Support\Search\CascadedDocuments;
use App\Support\Search\DocumentsLeaving;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class ForumBoard extends Model
{
    use HasFactory;

    protected $fillable = [
        'forum_category_id',
        'title',
        'slug',
        'description',
        'position',
        'prefixes_required',
    ];

    protected $casts = [
        'prefixes_required' => 'boolean',
    ];

    /**
     * The documents of the threads this board is being deleted with, found by `deleting` and forgotten by `deleted`.
     */
    private ?DocumentsLeaving $documentsLeaving = null;

    protected static function booted(): void
    {
        // The threads and replies of the board go with it by ON DELETE CASCADE, which runs no model events: the files go first, and the
        // threads' documents in the search index are asked to go once the board is.
        static::deleting(function (ForumBoard $board): void {
            app(ForumAttachments::class)->forgetInBoard($board);

            $board->documentsLeaving = app(CascadedDocuments::class)->before([
                ForumThread::class => DB::table('forum_threads')->where('forum_board_id', $board->id),
            ]);
        });

        static::deleted(function (ForumBoard $board): void {
            app(CascadedDocuments::class)->after($board->documentsLeaving);
            $board->documentsLeaving = null;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<ForumPrefix, $this>
     */
    public function prefixes(): BelongsToMany
    {
        return $this->belongsToMany(ForumPrefix::class, 'forum_board_forum_prefix');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ForumCategory::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(ForumThread::class)
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_posted_at');
    }

    public function publishedThreads(): HasMany
    {
        return $this->hasMany(ForumThread::class)
            ->where('is_published', true)
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_posted_at');
    }

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(ForumPost::class, ForumThread::class);
    }

    public function latestThread(): HasOne
    {
        return $this->hasOne(ForumThread::class)
            ->where('is_published', true)
            ->latestOfMany('last_posted_at');
    }
}
