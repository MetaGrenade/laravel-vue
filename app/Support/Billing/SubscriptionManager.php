<?php

namespace App\Support\Billing;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Arr;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
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
     * Ask Stripe to take the payment a subscription is behind on, now. Stripe does not try again when a card is changed, only
     * on its own schedule, so without this a customer who has put their card right waits (with their plan paused) for the next
     * attempt.
     */
    public function retryPayment(User $user): PaymentRetry
    {
        $subscription = $user->subscription($this->subscriptionName());

        if ($subscription === null || ! ($subscription->pastDue() || $subscription->stripe_status === StripeSubscription::STATUS_UNPAID)) {
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

            return $subscription->valid() ? PaymentRetry::paid() : PaymentRetry::nothingDue();
        }

        // The webhook says so too, but the customer should not have to wait for it to get their plan back.
        $subscription->syncStripeStatus();

        return PaymentRetry::paid();
    }

    protected function subscriptionName(): string
    {
        return (string) config('billing.subscription_name', 'default');
    }
}
