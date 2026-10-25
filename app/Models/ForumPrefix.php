<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A label staff offer on one or more boards. A thread carries at most one (docs/forum-prefixes.md).
 *
 * @property int $threads_count
 */
class ForumPrefix extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'color',
        'position',
    ];

    /**
     * @return BelongsToMany<ForumBoard, $this>
     */
    public function boards(): BelongsToMany
    {
        return $this->belongsToMany(ForumBoard::class, 'forum_board_forum_prefix');
    }

    /**
     * @return HasMany<ForumThread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(ForumThread::class);
    }
}
