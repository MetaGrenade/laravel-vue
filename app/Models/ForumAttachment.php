<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A picture or file kept with a forum post (docs/forum-attachments.md).
 *
 * The file lives on a private disk. Nothing serves it except the attachment route, and only when the post itself can be seen.
 */
class ForumAttachment extends Model
{
    protected $fillable = [
        'forum_post_id',
        'user_id',
        'disk',
        'path',
        'name',
        'mime_type',
        'size',
        'width',
        'height',
    ];

    protected $hidden = [
        'disk',
        'path',
    ];

    protected static function booted(): void
    {
        static::deleting(function (ForumAttachment $attachment): void {
            $attachment->deleteFile();
        });
    }

    /**
     * Delete the file this row names. The disks are set not to throw, so a file the disk refuses to delete comes back as `false`
     * and nothing said so: it is reported here, with its name, so that it can be deleted by hand. A disk the site has no
     * configuration for still throws.
     */
    public function deleteFile(): void
    {
        if (! $this->path) {
            return;
        }

        if (! Storage::disk((string) $this->disk)->delete($this->path)) {
            report(new RuntimeException("The file {$this->path} of the forum attachment {$this->getKey()} could not be deleted."));
        }
    }

    /**
     * @return BelongsTo<ForumPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'forum_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
