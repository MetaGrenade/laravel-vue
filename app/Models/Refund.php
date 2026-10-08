<?php

namespace App\Models;

use App\Enums\RefundStatus;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderRefunder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money returned to a customer for an order. Created and settled only by
 * {@see OrderRefunder}.
 */
class Refund extends Model
{
    /**
     * Why a refund was made. Stripe knows the first three; "other" is for our own records.
     *
     * @var list<string>
     */
    public const REASONS = ['requested_by_customer', 'duplicate', 'fraudulent', 'other'];

    protected $fillable = [
        'order_id',
        'payment_id',
        'user_id',
        'provider',
        'provider_reference',
        'idempotency_key',
        'status',
        'amount',
        'currency',
        'reason',
        'note',
        'failure_reason',
        'restock',
        'notify_customer',
        'processed_at',
    ];

    protected $casts = [
        'status' => RefundStatus::class,
        'amount' => 'decimal:2',
        'restock' => 'boolean',
        'notify_customer' => 'boolean',
        'processed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function money(): Money
    {
        return Money::parse($this->amount, $this->currency);
    }

    /**
     * Whether the money went back through a payment provider (rather than
     * being recorded by hand).
     */
    public function isProviderRefund(): bool
    {
        return $this->provider !== null;
    }
}
