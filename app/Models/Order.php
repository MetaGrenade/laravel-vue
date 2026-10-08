<?php

namespace App\Models;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Concerns\HasOwner;
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
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
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

    public function isPaid(): bool
    {
        return $this->payment_status === OrderPaymentStatus::Paid;
    }
}
