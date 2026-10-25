<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One rule of the forum's automatic moderation (docs/automod.md): what to look for, what to do with a post that has it, and who it is for.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property array<string, mixed> $settings
 * @property string $action
 * @property int|null $only_new_days
 * @property bool $is_enabled
 * @property int $hits
 * @property Carbon|null $last_hit_at
 */
class AutomodRule extends Model
{
    use HasFactory;

    /** Phrases that must not appear. */
    public const WORDS = 'words';

    /** Too many links, or a link to a domain that is not wanted. */
    public const LINKS = 'links';

    /** The same text posted again. */
    public const DUPLICATE = 'duplicate';

    /** Too many posts in a short time. */
    public const RATE = 'rate';

    public const TYPES = [self::WORDS, self::LINKS, self::DUPLICATE, self::RATE];

    /** Kept out of sight until a moderator approves it. */
    public const HOLD = 'hold';

    /** Refused: the author is told it looks like spam. */
    public const REJECT = 'reject';

    public const ACTIONS = [self::HOLD, self::REJECT];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'only_new_days' => 'integer',
            'is_enabled' => 'boolean',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }
}
