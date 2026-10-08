<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in an order's history. Append-only: events are written, never edited.
 */
class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const FULFILLED = 'fulfilled';

    public const NOTE = 'note';

    public const REFUND_REQUESTED = 'refund_requested';

    public const REFUND_SUCCEEDED = 'refund_succeeded';

    public const REFUND_FAILED = 'refund_failed';

    public const REFUND_UNCONFIRMED = 'refund_unconfirmed';

    protected $fillable = [
        'order_id',
        'user_id',
        'type',
        'message',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function record(Order $order, string $type, ?string $message = null, array $data = [], User|int|null $by = null): self
    {
        return self::create([
            'order_id' => $order->id,
            'user_id' => $by instanceof User ? $by->id : $by,
            'type' => $type,
            'message' => $message,
            'data' => $data ?: null,
        ]);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
