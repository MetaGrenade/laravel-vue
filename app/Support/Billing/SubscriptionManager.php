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
use Stripe\Exception\CardException;
use Stripe\Exception\ExceptionInterface;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpKernel\Exception\LockedHttpException;

class SubscriptionManager
{
    public function __construct(private readonly PromotionCodes $promotionCodes) {}

    /**
     * Start a subscription. Never at the same time as the user is being deleted (see {@see deleteUser()}): the two take
     * the same lock, and once this one has it, it looks for the user again, since the account may have gone while it waited.
     *
     * @param  array{promotion_code?: string|null}  $options  `promotion_code` is a code the customer typed; it is looked up here, whatever
     *                                                        the page checked before, and the subscription is not started with a
     *                                                        code that cannot be used
     *
     * @throws AuthenticationException the account no longer exists
     * @throws LockedHttpException the account is being deleted, or another subscription is being started, and did not finish in time
     * @throws PromotionCodeRejected the promotion code cannot be used (nothing was started or charged)
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

    /**
     * The days of free trial this customer gets on this plan: the plan's, if they are eligible, otherwise none.
     */
    public function trialDaysFor(?User $user, SubscriptionPlan $plan): int
    {
        return $plan->trial_days !== null && $this->eligibleForTrial($user) ? $plan->trial_days : 0;
    }

    /**
     * Whether a customer may still have a free trial. A customer gets **one**: someone who has ever had a subscription (running,
     * cancelled or ended) is charged from the start, or a trial could be had again and again by cancelling and coming back. A
     * visitor with no account yet is eligible, as the account they are about to make will have none.
     */
    public function eligibleForTrial(?User $user): bool
    {
        return $user === null || ! Subscription::query()->where($user->getForeignKey(), $user->getKey())->exists();
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

        // Before the card is touched: a code that will not do is said so while nothing has happened yet.
        $offer = null;

        if (filled($code = Arr::get($options, 'promotion_code'))) {
            $offer = $this->promotionCodes->find($user, $plan, (string) $code);

            $builder->withPromotionCode($offer->id);
        }

        $trialDays = $this->trialDaysFor($user, $plan);

        if ($trialDays > 0) {
            $builder->trialDays($trialDays);
        }

        $user->updateDefaultPaymentMethod($paymentMethod);

        try {
            return $builder->create($paymentMethod, [
                'metadata' => [
                    'plan_id' => $plan->getKey(),
                ],
            ]);
        } catch (InvalidRequestException $exception) {
            // Stripe holds a code to more than can be seen beforehand (first-time customers only, a minimum payment).
            if ($offer === null || ! $this->aboutPromotionCode($exception)) {
                throw $exception;
            }

            Log::info('Stripe refused a promotion code when a subscription was started.', [
                'user_id' => $user->getKey(),
                'error' => $exception->getMessage(),
            ]);

            throw PromotionCodeRejected::refusedByStripe();
        }
    }

    private function aboutPromotionCode(InvalidRequestException $exception): bool
    {
        return str_contains((string) $exception->getError()?->param, 'promotion_code')
            || preg_match('/promotion code|coupon/i', $exception->getMessage()) === 1;
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

    /**
     * Ask Stripe to take the payment a subscription is behind on, now. Stripe does not try again when a card is changed, only
     * on its own schedule, so without this a customer who has put their card right waits (with their plan paused) for the next
     * attempt.
     */
    public function retryPayment(User $user): PaymentRetry
    {
        $subscription = $user->subscription($this->subscriptionName());

        if ($subscription === null || ! $this->behindOnPayment($subscription)) {
            return PaymentRetry::nothingDue();
        }

        try {
            return $this->payOverdue($subscription);
        } catch (ApiErrorException $exception) {
            // Stripe could not be reached, or would not talk to us: nothing was paid, and the customer can try again.
            report($exception);

            return PaymentRetry::unavailable();
        }
    }

    private function payOverdue(Subscription $subscription): PaymentRetry
    {
        $stripe = Cashier::stripe();
        $invoiceId = $stripe->subscriptions->retrieve($subscription->stripe_id)->latest_invoice;

        if (! is_string($invoiceId)) {
            return PaymentRetry::nothingDue();
        }

        try {
            $stripe->invoices->pay($invoiceId);
        } catch (CardException $exception) {
            // The card wants the customer (3-D Secure): Stripe's own page for the invoice is where they do that.
            if ($exception->getStripeCode() === 'authentication_required') {
                $url = $stripe->invoices->retrieve($invoiceId)->hosted_invoice_url;

                if (is_string($url) && $url !== '') {
                    return PaymentRetry::actionRequired($url);
                }
            }

            return PaymentRetry::declined($exception->getError()->message ?? 'Your card was declined.');
        } catch (InvalidRequestException) {
            // Not payable any more (Stripe took it meanwhile, or voided it): what Stripe says now is what counts.
            $subscription->syncStripeStatus();

            return $this->recovered($subscription) ? PaymentRetry::paid() : PaymentRetry::nothingDue();
        }

        // The webhook says so too, but the customer should not have to wait for it to get their plan back.
        $subscription->syncStripeStatus();

        // Stripe taking the request is not the money arriving: a bank debit is still processing, and the subscription may not
        // have caught up. Say the plan is back only when the subscription, as Stripe now says, is no longer behind.
        return $this->recovered($subscription) ? PaymentRetry::paid() : PaymentRetry::pending();
    }

    /**
     * Whether a subscription is behind on its payment: past due, or unpaid. The billing page says so and offers to pay, and this
     * is what there is to pay for.
     */
    public function behindOnPayment(Subscription $subscription): bool
    {
        return $subscription->pastDue() || $subscription->stripe_status === StripeSubscription::STATUS_UNPAID;
    }

    /**
     * Whether a subscription, as Stripe last said, is back: not behind, and live (what its plan gives follows `valid()`).
     */
    private function recovered(Subscription $subscription): bool
    {
        return ! $this->behindOnPayment($subscription) && $subscription->valid();
    }

    protected function subscriptionName(): string
    {
        return (string) config('billing.subscription_name', 'default');
    }
}
