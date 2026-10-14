<?php

namespace App\Models;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Concerns\HasOwner;
use App\Support\Commerce\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;
    use HasOwner;

    protected $fillable = [
        'user_id',
        'cart_id',
        'status',
        'payment_status',
        'payment_provider',
        'currency',
        'subtotal',
        'tax_total',
        'shipping_total',
        'discount_total',
        'grand_total',
        'coupon_id',
        'coupon_code',
        'gift_card_id',
        'gift_card_total',
        'customer_email',
        'customer_name',
        'idempotency_key',
        'shipping_method',
        'shipping_address',
        'billing_address',
        'placed_at',
        'paid_at',
        'fulfilled_at',
        'cancelled_at',
        'expires_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'payment_status' => OrderPaymentStatus::class,
        'metadata' => 'array',
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'gift_card_total' => 'decimal:2',
        'refunded_total' => 'decimal:2',
        'placed_at' => 'datetime',
        'paid_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            // The public id is what appears in links: unguessable, unlike the row id.
            $order->public_id ??= strtolower((string) Str::ulid());
        });

        static::created(function (Order $order) {
            if ($order->number === null) {
                $order->forceFill([
                    'number' => config('commerce.orders.number_prefix', 'MF').'-'.str_pad((string) $order->getKey(), 6, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }
        });
    }

    /**
     * Orders are addressed by their public id in URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * The discount code the order was placed with. The order keeps its own copy of the code and
     * what it was worth, so editing the coupon later does not change it.
     *
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * The gift card that paid part (or all) of the order, for as much as `gift_card_total` says.
     *
     * @return BelongsTo<GiftCard, $this>
     */
    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    /**
     * What the payment provider is asked for: the order's total less the part a gift card paid. The order itself
     * is still worth `grand_total` (refunds, tax and reports go by that).
     */
    public function amountDue(): Money
    {
        return Money::parse($this->grand_total, $this->currency)->subtract(Money::parse($this->gift_card_total ?? 0, $this->currency));
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<DownloadGrant, $this>
     */
    public function downloadGrants(): HasMany
    {
        return $this->hasMany(DownloadGrant::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    /**
     * Whether the customer's money was received. True for a refunded order too:
     * it was paid for, so it can neither be paid again nor cancelled as unpaid.
     */
    public function isPaid(): bool
    {
        return $this->payment_status->hasReceivedPayment();
    }
}
