<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a moderator did to a member: a warning, a ban, lifting a ban, or taking down what they had posted (docs/moderation.md). Rows
 * are written once and never changed, so there is no `updated_at`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $actor_id
 * @property string $type
 * @property string|null $reason
 * @property string|null $note
 * @property array<string, int>|null $details
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

    /** Their threads, replies, comments and reviews were taken down. A matter for staff: the member is not told of it. */
    public const REMOVAL = 'removal';

    public const TYPES = [self::WARNING, self::BAN, self::UNBAN, self::REMOVAL];

    /** What the member is told, and so can read in their account and in their data export. */
    public const TOLD_TO_MEMBER = [self::WARNING, self::BAN, self::UNBAN];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'details' => 'array',
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
