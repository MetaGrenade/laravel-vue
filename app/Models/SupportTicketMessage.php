<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $user_id
 * @property string $body
 */
class SupportTicketMessage extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'body',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<SupportTicketMessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketMessageAttachment::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $message): void {
            foreach ($message->attachments()->cursor() as $attachment) {
                $attachment->delete();
            }
        });
    }
}
