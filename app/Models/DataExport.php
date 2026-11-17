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
     * Where, on the `local` disk, the zip of an export is written. Nothing else is kept there, and PruneDataExports empties it.
     */
    public const DIRECTORY = 'exports';

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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    /**
     * The pattern a path has to match to be the file of an export: a file directly in the exports directory.
     *
     * Staff could once save any string as the path (any file on the private disk: a support attachment, a product download), and the
     * row of such an export is still in the database of a site that upgraded. So this is asked of the path that is STORED, wherever
     * the site opens, serves or deletes it, and not only of what staff type now (UpdateDataExportRequest).
     */
    public static function pathPattern(): string
    {
        return '#^'.preg_quote(self::DIRECTORY, '#').'/[A-Za-z0-9][A-Za-z0-9._-]*$#D';
    }

    public function namesAnExportFile(): bool
    {
        return is_string($this->file_path) && preg_match(self::pathPattern(), $this->file_path) === 1;
    }

    public function isReady(): bool
    {
        if ($this->status !== self::STATUS_COMPLETED || ! $this->file_path || ! $this->namesAnExportFile()) {
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

        // Only a file of the exports directory is deleted. A path saved before staff were held to that (any private file) is forgotten by
        // the row and its file is left alone: PruneDataExports calls this for every expired row, with nobody looking.
        if ($this->namesAnExportFile() && Storage::disk('local')->exists($this->file_path)) {
            Storage::disk('local')->delete($this->file_path);
        }

        $this->forceFill(['file_path' => null])->saveQuietly();
    }
}
