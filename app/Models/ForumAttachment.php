<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
            if ($attachment->path) {
                Storage::disk((string) $attachment->disk)->delete($attachment->path);
            }
        });
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
