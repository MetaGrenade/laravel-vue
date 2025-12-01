<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'discount_type',
        'amount_off',
        'percent_off',
        'max_redemptions',
        'redeemed_count',
        'min_amount',
        'subscription_plan_id',
        'bonus_trial_days',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'amount_off' => 'integer',
        'percent_off' => 'integer',
        'max_redemptions' => 'integer',
        'redeemed_count' => 'integer',
        'min_amount' => 'integer',
        'bonus_trial_days' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof Carbon && $this->expires_at->isPast();
    }

    public function discountAmountFor(int $amount): int
    {
        if ($this->discount_type === 'percent' && $this->percent_off !== null) {
            return (int) floor($amount * $this->percent_off / 100);
        }

        if ($this->discount_type === 'amount' && $this->amount_off !== null) {
            return min($amount, (int) $this->amount_off);
        }

        return 0;
    }

    public function remainingRedemptions(): ?int
    {
        if ($this->max_redemptions === null) {
            return null;
        }

        return max($this->max_redemptions - $this->redeemed_count, 0);
    }
}
