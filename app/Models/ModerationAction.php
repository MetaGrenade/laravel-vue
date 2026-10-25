<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a moderator did to a member: a warning, a ban, or lifting a ban (docs/moderation.md). Rows are written once and never
 * changed, so there is no `updated_at`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $actor_id
 * @property string $type
 * @property string|null $reason
 * @property string|null $note
 * @property Carbon|null $expires_at
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property bool $notified
 * @property Carbon $created_at
 */
class ModerationAction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const WARNING = 'warning';

    public const BAN = 'ban';

    public const UNBAN = 'unban';

    public const TYPES = [self::WARNING, self::BAN, self::UNBAN];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'notified' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
