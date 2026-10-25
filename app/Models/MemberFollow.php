<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member following another (docs/member-follows.md).
 */
class MemberFollow extends Model
{
    protected $fillable = [
        'user_id',
        'followed_user_id',
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
    public function followed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_user_id');
    }
}
