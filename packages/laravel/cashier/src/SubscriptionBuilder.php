<?php

namespace Laravel\Cashier;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SubscriptionBuilder
{
    protected ?int $trialDays = null;
    protected ?string $coupon = null;

    public function __construct(
        protected $user,
        protected string $name,
        protected string $stripePrice
    ) {
    }

    public function trialDays(int $days): self
    {
        $this->trialDays = $days;

        return $this;
    }

    public function withCoupon(?string $coupon): self
    {
        $this->coupon = $coupon;

        return $this;
    }

    public function create(string $paymentMethod, array $options = []): Subscription
    {
        $trialEndsAt = null;

        if ($this->trialDays !== null) {
            $trialEndsAt = Carbon::now()->addDays($this->trialDays);
        }

        /** @var Subscription $subscription */
        $subscription = $this->user->subscriptions()->create([
            'owner_id' => $this->user->getKey(),
            'owner_type' => get_class($this->user),
            'name' => $this->name,
            'stripe_id' => $options['stripe_id'] ?? ('sub_'.Str::random(26)),
            'stripe_status' => $options['status'] ?? 'active',
            'stripe_price' => $this->stripePrice,
            'quantity' => $options['quantity'] ?? 1,
            'payment_method' => $paymentMethod,
            'trial_ends_at' => $trialEndsAt,
            'coupon' => $this->coupon,
            'metadata' => $options['metadata'] ?? [],
        ]);

        return $subscription;
    }
}
