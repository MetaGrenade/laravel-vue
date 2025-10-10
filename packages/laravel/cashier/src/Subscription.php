<?php

namespace Laravel\Cashier;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $table = 'subscriptions';

    protected $fillable = [
        'owner_id',
        'owner_type',
        'name',
        'stripe_id',
        'stripe_status',
        'stripe_price',
        'quantity',
        'payment_method',
        'coupon',
        'trial_ends_at',
        'ends_at',
        'metadata',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo($this->owner_type, 'owner_id');
    }

    public function active(): bool
    {
        if ($this->ended()) {
            return false;
        }

        if ($this->cancelled() && ! $this->onGracePeriod()) {
            return false;
        }

        return in_array($this->stripe_status, ['active', 'trialing', 'incomplete', 'incomplete_expired'], true)
            || $this->onGracePeriod();
    }

    public function cancelled(): bool
    {
        return $this->ends_at !== null;
    }

    public function ended(): bool
    {
        return $this->ends_at !== null && $this->ends_at instanceof CarbonInterface && $this->ends_at->isPast();
    }

    public function onGracePeriod(): bool
    {
        return $this->ends_at !== null && $this->ends_at instanceof CarbonInterface && $this->ends_at->isFuture();
    }

    public function cancel(?CarbonInterface $at = null): self
    {
        $this->stripe_status = 'canceled';
        $this->ends_at = $at ?? now()->addDay();
        $this->save();

        return $this;
    }

    public function cancelNow(): self
    {
        $this->stripe_status = 'canceled';
        $this->ends_at = now();
        $this->save();

        return $this;
    }

    public function resume(): self
    {
        $this->stripe_status = 'active';
        $this->ends_at = null;
        $this->save();

        return $this;
    }
}
