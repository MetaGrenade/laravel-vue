<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'status',
        'currency',
        'subtotal',
        'coupon_id',
        'gift_card_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'subtotal' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The discount code the shopper applied, if any. Checked again whenever the cart is priced.
     *
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * The gift card the shopper applied, if any. Checked again whenever the cart is priced.
     *
     * @return BelongsTo<GiftCard, $this>
     */
    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        // A line asked for its cart (to price itself in the cart's currency) is given the one it was loaded with, not a query for each line.
        return $this->hasMany(CartItem::class)->chaperone();
    }
}
