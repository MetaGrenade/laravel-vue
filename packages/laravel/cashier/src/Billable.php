<?php

namespace Laravel\Cashier;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

trait Billable
{
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'owner');
    }

    public function subscription(string $name = 'default'): ?Subscription
    {
        return $this->subscriptions()
            ->where('name', $name)
            ->latest('id')
            ->first();
    }

    public function subscribed(string $name = 'default', string|array|null $price = null): bool
    {
        $subscription = $this->subscription($name);

        if (! $subscription || ! $subscription->active()) {
            return false;
        }

        if ($price === null) {
            return true;
        }

        $prices = is_array($price) ? $price : [$price];

        return in_array($subscription->stripe_price, $prices, true);
    }

    public function newSubscription(string $name, string $stripePrice): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $name, $stripePrice);
    }

    public function createSetupIntent(): array
    {
        return [
            'id' => 'seti_'.Str::random(24),
            'client_secret' => 'seti_client_secret_'.Str::random(32),
        ];
    }
}
