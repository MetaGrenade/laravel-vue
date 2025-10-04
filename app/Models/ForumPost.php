<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ForumPost extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'forum_thread_id',
        'user_id',
        'body',
        'edited_at',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ForumThread::class, 'forum_thread_id');
    }

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
}
