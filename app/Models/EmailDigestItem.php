<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One community update waiting for a member's daily email (docs/email-digests.md).
 *
 * @property int $user_id
 * @property string $category
 * @property string $title
 * @property string|null $excerpt
 * @property string $url
 */
class EmailDigestItem extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'title',
        'excerpt',
        'url',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
