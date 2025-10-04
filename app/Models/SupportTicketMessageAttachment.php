<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SupportTicketMessageAttachment extends Model
{
    protected $fillable = [
        'support_ticket_message_id',
        'disk',
        'path',
        'name',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $attachment): void {
            if (! $attachment->disk || ! $attachment->path) {
                return;
            }

            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }
}
