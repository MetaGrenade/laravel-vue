<?php

namespace App\Models;

use App\Support\Commerce\GiftCards\GiftCards;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a gift card's balance. Append-only: written, never edited. Only
 * {@see GiftCards} writes them.
 */
class GiftCardTransaction extends Model
{
    public const UPDATED_AT = null;

    /** The card was made, with its first value. */
    public const ISSUED = 'issued';

    /** An order was placed with the card: its share of the total is held. */
    public const REDEEMED = 'redeemed';

    /** The order was cancelled or expired before it was paid: the held amount went back. */
    public const RELEASED = 'released';

    /** A paid order was refunded: money put back on the card. */
    public const REFUNDED = 'refunded';

    /** The team added or removed value (or replaced the code, which changes nothing). */
    public const ADJUSTED = 'adjusted';

    protected $fillable = [
        'gift_card_id',
        'type',
        'amount',
        'balance_after',
        'order_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<GiftCard, $this>
     */
    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
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
