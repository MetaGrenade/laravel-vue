<?php

namespace App\Support\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ExceptionInterface;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

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
            $builder->withCoupon($coupon);
        }

        if ($trialDays = Arr::get($options, 'trial_days')) {
            $builder->trialDays((int) $trialDays);
        }

        $user->updateDefaultPaymentMethod($paymentMethod);

        return $builder->create($paymentMethod, [
            'metadata' => [
                'plan_id' => $plan->getKey(),
            ],
        ]);
    }

    public function cancel(User $user): void
    {
        $subscription = $user->subscription($this->subscriptionName());

        if (! $subscription) {
            return;
        }

        $subscription->cancel();
    }

    /**
     * End every subscription the user still has at Stripe, now and not at the end of the period, so that deleting an
     * account never leaves a card being charged for it. Used before a user is deleted.
     *
     * Stripe is asked what is live for the customer rather than trusting the `subscriptions` table, which is only as
     * current as the last webhook. Nothing is prorated: the customer is being left behind, so a credit would sit on a
     * record nobody can use. The customer itself is kept, since its invoices are what tax and bookkeeping are checked
     * against. A user who never reached Stripe is left alone without contacting it.
     *
     * Any failure stops it and is raised, never swallowed: a subscription that could not be ended may still renew.
     * What was already ended stays ended, and trying again carries on with what is left.
     *
     * @throws SubscriptionCancellationFailed
     */
    public function cancelAllNow(User $user): void
    {
        $customerId = $user->stripeId();

        if ($customerId === null) {
            return;
        }

        $subscriptionId = null;

        try {
            $stripe = Cashier::stripe();

            $live = $this->unlessMissing($user, fn () => $this->liveSubscriptionIds($stripe, $customerId)) ?? [];

            foreach ($live as $subscriptionId) {
                $this->unlessMissing($user, fn () => $stripe->subscriptions->cancel($subscriptionId, ['prorate' => false]));

                Subscription::query()->where('stripe_id', $subscriptionId)->update([
                    'stripe_status' => StripeSubscription::STATUS_CANCELED,
                    'ends_at' => now(),
                ]);
            }
        } catch (ExceptionInterface $exception) {
            Log::error('Could not cancel a deleted account\'s Stripe subscription.', [
                'user_id' => $user->getKey(),
                'stripe_customer' => $customerId,
                'stripe_subscription' => $subscriptionId,
                'error' => $exception->getMessage(),
            ]);

            throw new SubscriptionCancellationFailed('The subscription could not be cancelled at Stripe.', previous: $exception);
        }
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

    /**
     * The ids of the customer's subscriptions that are not cancelled at Stripe, across every page.
     *
     * Every page is read before any subscription is ended: ending one while paging could move the cursor under the listing.
     *
     * @return list<string>
     */
    private function liveSubscriptionIds(StripeClient $stripe, string $customerId): array
    {
        $ids = [];

        foreach ($stripe->subscriptions->all(['customer' => $customerId, 'limit' => 100])->autoPagingIterator() as $subscription) {
            // Stripe leaves out the ones already cancelled; one that expired unpaid never started.
            if ($subscription->status !== StripeSubscription::STATUS_INCOMPLETE_EXPIRED) {
                $ids[] = $subscription->id;
            }
        }

        return $ids;
    }

    /**
     * Make a Stripe call, taking "no such customer or subscription" as done (null): there is nothing there left to
     * renew, and an account whose customer was deleted in the dashboard must not become undeletable for good.
     */
    private function unlessMissing(User $user, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException $exception) {
            if ($exception->getStripeCode() !== 'resource_missing') {
                throw $exception;
            }

            Log::warning('Stripe has no record of a deleted account\'s subscription, so there was nothing to cancel.', [
                'user_id' => $user->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function subscriptionName(): string
    {
        return (string) config('billing.subscription_name', 'default');
    }
}
