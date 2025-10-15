<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class DataExport extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public const DOWNLOAD_TTL_MINUTES = 30;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'format',
        'file_path',
        'failure_reason',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function isReady(): bool
    {
        if ($this->status !== self::STATUS_COMPLETED || ! $this->file_path) {
            return false;
        }

        if ($this->hasExpired()) {
            $this->purgeExpiredFile();

            return false;
        }

        return Storage::disk('local')->exists($this->file_path);
    }

    public function downloadExpiresAt(): ?Carbon
    {
        if (! $this->completed_at) {
            return null;
        }

        return $this->completed_at->copy()->addMinutes(self::DOWNLOAD_TTL_MINUTES);
    }

    public function hasExpired(): bool
    {
        if (! $this->completed_at) {
            return false;
        }

        return $this->completed_at->copy()->addMinutes(self::DOWNLOAD_TTL_MINUTES)->isPast();
    }

    public function purgeExpiredFile(): void
    {
        if (! $this->file_path) {
            return;
        }

        if (Storage::disk('local')->exists($this->file_path)) {
            Storage::disk('local')->delete($this->file_path);
        }

        $this->forceFill(['file_path' => null])->saveQuietly();
    }
}
