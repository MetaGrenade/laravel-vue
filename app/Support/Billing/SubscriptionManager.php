<?php

namespace App\Support\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ExceptionInterface;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpKernel\Exception\LockedHttpException;

class SubscriptionManager
{
    /**
     * Start a subscription. Never at the same time as the user is being deleted (see {@see deleteUser()}): the two take
     * the same lock, and once this one has it, it looks for the user again, since the account may have gone while it waited.
     *
     * @throws AuthenticationException the account no longer exists
     * @throws LockedHttpException the account is being deleted, or another subscription is being started, and did not finish in time
     */
    public function create(User $user, SubscriptionPlan $plan, string $paymentMethod, array $options = []): ?Subscription
    {
        if ($paymentMethod === '') {
            throw IncompletePayment::invalidPaymentMethod();
        }

        try {
            return $this->locked($user, function () use ($user, $plan, $paymentMethod, $options) {
                if (! User::query()->whereKey($user->getKey())->exists()) {
                    throw new AuthenticationException;
                }

                return $this->subscribe($user, $plan, $paymentMethod, $options);
            });
        } catch (LockTimeoutException $exception) {
            throw new LockedHttpException('Your subscription is being changed right now. Please try again in a moment.', $exception);
        }
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
     * Delete a user without leaving a subscription behind to charge them: every subscription they have at Stripe is ended
     * first (see {@see cancelAllNow()}), then the user is deleted. If any cannot be ended the user is not deleted.
     *
     * The user's lock is held from before Stripe is asked until the row is gone, and {@see create()} takes the same one.
     * Without it a subscription started in that time would be missed by the listing and, with the user gone, would go on
     * charging with nobody to ask. Whichever comes second waits for the first: a subscription started first is listed and
     * ended with the rest, and one started after finds the account gone.
     *
     * @param  (Closure(): void)|null  $beforeDelete  Runs once the subscriptions are ended, just before the user is deleted
     *                                                (the profile page signs the person out here).
     *
     * @throws SubscriptionCancellationFailed
     */
    public function deleteUser(User $user, ?Closure $beforeDelete = null): void
    {
        try {
            $this->locked($user, function () use ($user, $beforeDelete) {
                $this->cancelAllNow($user);

                if ($beforeDelete !== null) {
                    $beforeDelete();
                }

                $user->delete();
            });
        } catch (LockTimeoutException $exception) {
            Log::warning('Could not delete an account: its subscription is being changed and did not finish in time.', [
                'user_id' => $user->getKey(),
            ]);

            throw new SubscriptionCancellationFailed('The subscription is being changed and could not be cancelled yet.', previous: $exception);
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

    private function subscribe(User $user, SubscriptionPlan $plan, string $paymentMethod, array $options): Subscription
    {
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

    /**
     * End every subscription the user still has at Stripe, now and not at the end of the period, so that deleting an
     * account never leaves a card being charged for it. Only called with the user's lock held, by {@see deleteUser()}.
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
    private function cancelAllNow(User $user): void
    {
        // Read again rather than asking the model: it may be older than a subscription another request has just started.
        $customerId = User::query()->whereKey($user->getKey())->value('stripe_id');

        if (! is_string($customerId) || $customerId === '') {
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

    /**
     * Run a callback holding the user's billing lock, waiting for whoever has it. A lock that is never released (the
     * process died) lets go by itself after `billing.locks.ttl` seconds.
     *
     * @throws LockTimeoutException
     */
    private function locked(User $user, Closure $callback): mixed
    {
        return Cache::lock('billing:user:'.$user->getKey(), (int) config('billing.locks.ttl', 300))
            ->block((int) config('billing.locks.wait', 15), $callback);
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
