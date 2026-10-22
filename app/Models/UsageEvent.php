<?php

namespace App\Models;

use App\Support\Usage\Usage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of something that is metered, by one account (see docs/usage.md). Rows are only added (by {@see Usage}) and
 * removed when they are older than the retention period or their account is deleted.
 */
class UsageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'meter', 'quantity', 'occurred_at', 'idempotency_key', 'token_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
