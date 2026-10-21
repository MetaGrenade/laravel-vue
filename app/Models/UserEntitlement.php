<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use App\Support\Entitlements\OrderEntitlements;
use Database\Factories\UserEntitlementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entitlement given to whoever owns an order, because of one line of it. Written by {@see OrderEntitlements}, which
 * also takes it back (`revoked_at`) and gives it back as the money or the recurring payment behind it comes and goes.
 */
class UserEntitlement extends Model
{
    /** @use HasFactory<UserEntitlementFactory> */
    use HasFactory;

    use HasOwner;

    /** Taken back because the order was refunded in full (or a dispute over it was lost). */
    public const BY_REFUND = 'refund';

    /** Taken back because the recurring payment it came with has ended. */
    public const BY_SUBSCRIPTION = 'subscription';

    protected $fillable = [
        'entitlement_id',
        'source',
        'order_id',
        'order_item_id',
        'value',
        'expires_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'value' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Entitlement, $this>
     */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
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
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Grants that count now: not taken back, and not past their end.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('revoked_at'))
            ->where(fn (Builder $query) => $query
                ->whereNull($this->qualifyColumn('expires_at'))
                ->orWhere($this->qualifyColumn('expires_at'), '>', now()));
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
