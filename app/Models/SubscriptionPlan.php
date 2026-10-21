<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'stripe_price_id',
        'interval',
        'price',
        'currency',
        'description',
        'features',
        'is_active',
    ];

    protected $casts = [
        'price' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $plan) {
            if (! $plan->slug) {
                $plan->slug = Str::slug($plan->name);
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    /**
     * What the plan gives the account that holds it, each with how much (for a limit).
     *
     * @return HasMany<SubscriptionPlanEntitlement, $this>
     */
    public function entitlementLinks(): HasMany
    {
        return $this->hasMany(SubscriptionPlanEntitlement::class);
    }

    /**
     * Whether anyone is subscribed to the plan right now (a trial, a grace period after cancelling, and a paid subscription
     * all count), which is when changing or removing it takes something away from them. Subscriptions are matched to a plan
     * by Stripe price, as everywhere else.
     */
    public function hasLiveSubscribers(): bool
    {
        return Subscription::query()
            ->where('stripe_price', $this->stripe_price_id)
            ->lazy()
            ->contains(fn (Subscription $subscription) => $subscription->valid());
    }
}
