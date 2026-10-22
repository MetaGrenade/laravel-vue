<?php

namespace App\Support\Billing;

use App\Models\BillingDunningNotice;
use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\SubscriptionCancelledForNonPayment;
use App\Notifications\SubscriptionPaymentFailed;
use App\Notifications\SubscriptionPaymentRecovered;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription;

/**
 * Tells a subscriber when a payment for their plan fails, when it goes through after failing, and when the subscription ends
 * because it never did.
 *
 * Stripe decides when to try again and when to give up (Settings → Billing → Revenue recovery, in Stripe); this only follows
 * what its webhooks say. Each notice is **claimed** before it is sent, one per kind, invoice (or subscription) and attempt, so
 * a webhook Stripe delivers twice, or one an admin replays, tells the customer once.
 */
class Dunning
{
    public function __construct(private readonly BillingNotices $notices) {}

    /**
     * A payment for an invoice failed: tell the subscriber, once for each attempt.
     *
     * @param  array<string, mixed>  $payload  The Stripe event.
     */
    public function paymentFailed(?BillingInvoice $invoice, array $payload): void
    {
        $user = $invoice?->user;

        if ($invoice === null || $user === null) {
            return;
        }

        $stripeInvoice = (array) Arr::get($payload, 'data.object', []);

        // Only a subscription's invoice is about a plan: a manual or one-off invoice failing is not this email's business.
        if (! $this->belongsToSubscription($stripeInvoice)) {
            return;
        }

        // The first payment of a new subscription is taken while the customer is on the page that asked for it, which says so.
        if (Arr::get($stripeInvoice, 'billing_reason') === 'subscription_create') {
            return;
        }

        $attempt = max(1, (int) Arr::get($stripeInvoice, 'attempt_count', 1));
        $next = Arr::get($stripeInvoice, 'next_payment_attempt');

        $this->notices->once(
            $user,
            BillingDunningNotice::FAILED,
            $invoice->stripe_id,
            $attempt,
            new SubscriptionPaymentFailed(
                $invoice,
                $attempt,
                is_numeric($next) ? Carbon::createFromTimestamp((int) $next) : null,
                // What Stripe tried to take, which is less than the invoice's total when a credit or balance came off first.
                (int) Arr::get($stripeInvoice, 'amount_due', $invoice->total),
            ),
        );
    }

    /**
     * An invoice was paid: if the customer was told it had failed, tell them it has gone through.
     *
     * @param  array<string, mixed>  $payload  The Stripe event.
     */
    public function paymentSucceeded(?BillingInvoice $invoice, array $payload): void
    {
        $user = $invoice?->user;

        if ($invoice === null || $user === null) {
            return;
        }

        $failedBefore = BillingDunningNotice::query()
            ->where('kind', BillingDunningNotice::FAILED)
            ->where('reference', $invoice->stripe_id)
            ->exists();

        if (! $failedBefore) {
            return;
        }

        // What was paid, not the invoice's total: a credit or balance may have covered part of it.
        $paid = (int) Arr::get($payload, 'data.object.amount_paid', $invoice->total);

        $this->notices->once($user, BillingDunningNotice::RECOVERED, $invoice->stripe_id, 0, new SubscriptionPaymentRecovered($invoice, $paid));
    }

    /**
     * A subscription was deleted: if Stripe did it because the payment could not be taken, tell the subscriber.
     *
     * @param  array<string, mixed>  $payload  The Stripe event.
     */
    public function subscriptionEnded(Subscription $subscription, array $payload): void
    {
        if (Arr::get($payload, 'data.object.cancellation_details.reason') !== 'payment_failed') {
            return;
        }

        $user = User::query()->find($subscription->user_id);

        if ($user === null) {
            return;
        }

        $plan = SubscriptionPlan::query()->where('stripe_price_id', $subscription->stripe_price)->value('name');

        $this->notices->once($user, BillingDunningNotice::CANCELLED, $subscription->stripe_id, 0, new SubscriptionCancelledForNonPayment($plan));
    }

    /**
     * Whether a Stripe invoice is a subscription's. The invoice names its subscription where the API version of the webhook
     * endpoint puts it: `parent.subscription_details.subscription` now, a top-level `subscription` on older versions.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function belongsToSubscription(array $invoice): bool
    {
        $subscription = Arr::get($invoice, 'parent.subscription_details.subscription') ?? Arr::get($invoice, 'subscription');

        return is_string($subscription) && $subscription !== '';
    }
}
