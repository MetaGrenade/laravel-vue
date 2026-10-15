<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time the shop refreshed its copy of the Tebex catalogue, and how it went.
 */
class TebexSync extends Model
{
    public const RUNNING = 'running';

    public const OK = 'ok';

    public const FAILED = 'failed';

    public const SCHEDULE = 'schedule';

    public const COMMAND = 'command';

    public const ADMIN = 'admin';

    protected $fillable = [
        'trigger',
        'status',
        'user_id',
        'started_at',
        'finished_at',
        'counts',
        'error',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'counts' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
