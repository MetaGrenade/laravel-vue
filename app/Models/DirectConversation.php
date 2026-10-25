<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The private conversation between two members (docs/direct-messages.md).
 *
 * The two ids are stored in order, so a pair has one row whichever of them writes first.
 *
 * @property CarbonInterface|null $last_message_at
 * @property int|null $unread_count
 */
class DirectConversation extends Model
{
    protected $fillable = [
        'user_low_id',
        'user_high_id',
        'last_message_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function low(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_low_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function high(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_high_id');
    }

    /**
     * @return HasMany<DirectMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DirectMessage::class);
    }

    /**
     * @return HasOne<DirectMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(DirectMessage::class)->latestOfMany();
    }
}
