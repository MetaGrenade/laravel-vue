<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
