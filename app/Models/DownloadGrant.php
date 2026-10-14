<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The right a paid order line gives to download its product's files. It ends when it expires or when
 * the order is refunded in full; how often each file may be downloaded is counted per file.
 */
class DownloadGrant extends Model
{
    use HasFactory;

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    /** Why a grant was revoked. */
    public const BY_REFUND = 'refunded';

    public const BY_STAFF = 'staff';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'product_id',
        'expires_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (DownloadGrant $grant) {
            $grant->public_id ??= strtolower((string) Str::ulid());
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<DownloadCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(DownloadCount::class);
    }

    /**
     * `revoked` (the order was refunded), `expired`, or `active`.
     */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return self::REVOKED;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::EXPIRED;
        }

        return self::ACTIVE;
    }

    public function isUsable(): bool
    {
        return $this->status() === self::ACTIVE;
    }
}
