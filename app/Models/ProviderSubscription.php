<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\HasOwner;
use App\Payments\Tebex\TebexSubscriptions;
use Database\Factories\ProviderSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring payment kept at a provider that is not Cashier (a Tebex recurring package), as far as the provider has
 * told the shop. Written only by {@see TebexSubscriptions}: the provider charges, retries and ends it.
 */
class ProviderSubscription extends Model
{
    /** @use HasFactory<ProviderSubscriptionFactory> */
    use HasFactory;

    use HasOwner;

    protected $fillable = [
        'order_id',
        'tebex_package_id',
        'provider',
        'reference',
        'package_name',
        'status',
        'provider_status',
        'amount',
        'currency',
        'renewals_count',
        'fail_count',
        'started_at',
        'last_payment_at',
        'next_payment_at',
        'cancellation_requested_at',
        'cancelled_at',
        'cancel_reason',
        'ended_at',
        'last_event_at',
        'raw',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'amount' => 'decimal:2',
        'renewals_count' => 'integer',
        'fail_count' => 'integer',
        'started_at' => 'datetime',
        'last_payment_at' => 'datetime',
        'next_payment_at' => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_event_at' => 'datetime',
        'raw' => 'array',
    ];

    /**
     * What the provider sent can hold the customer's details; keep it out of serialised output by default.
     *
     * @var list<string>
     */
    protected $hidden = ['raw'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<TebexPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TebexPackage::class, 'tebex_package_id');
    }

    /**
     * Whether it still runs: not ended, and neither expired nor cancelled.
     */
    public function isLive(): bool
    {
        return $this->ended_at === null && $this->status->isLive();
    }
}
