<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member choosing not to see another member's forum posts (docs/forum-ignores.md).
 */
class ForumIgnore extends Model
{
    protected $fillable = [
        'user_id',
        'ignored_user_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ignored(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_user_id');
    }
}
