<?php

namespace App\Support\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;

class SubscriptionManager
{
    public function create(User $user, SubscriptionPlan $plan, string $paymentMethod, array $options = []): ?Subscription
    {
        if ($paymentMethod === '') {
            throw IncompletePayment::invalidPaymentMethod();
        }

        $user->createOrGetStripeCustomer();

        $builder = $user->newSubscription($this->subscriptionName(), $plan->stripe_price_id);

        if ($coupon = Arr::get($options, 'coupon')) {
            $builder->withCoupon(is_string($coupon) ? $coupon : ($coupon->code ?? null));
        }

        $trialDays = Arr::get($options, 'trial_days', $plan->trial_days ?? null);

        if ($trialDays) {
            $builder->trialDays((int) $trialDays);
        }

        $user->updateDefaultPaymentMethod($paymentMethod);

        $subscription = $builder->create($paymentMethod, [
            'metadata' => [
                'plan_id' => $plan->getKey(),
            ],
        ]);

        if ($subscription && $trialDays) {
            $subscription->forceFill([
                'trial_started_at' => Carbon::now(),
            ])->save();
        }

        return $subscription;
    }

    public function cancel(User $user): void
    {
        $subscription = $user->subscription($this->subscriptionName());

        if (! $subscription) {
            return;
        }

        $subscription->cancel();
    }

    public function resume(User $user): void
    {
        $subscription = $user->subscription($this->subscriptionName());

        if (! $subscription) {
            return;
        }

        if (! $subscription->canceled() || $subscription->ended()) {
            return;
        }

        $subscription->resume();
    }

    protected function subscriptionName(): string
    {
        return (string) config('billing.subscription_name', 'default');
    }
}
