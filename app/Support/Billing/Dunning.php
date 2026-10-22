<?php

namespace App\Support\Billing;

use App\Models\BillingDunningNotice;
use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\SubscriptionCancelledForNonPayment;
use App\Notifications\SubscriptionPaymentFailed;
use App\Notifications\SubscriptionPaymentRecovered;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // The first payment of a new subscription is taken while the customer is on the page that asked for it, which says so.
        if (Arr::get($stripeInvoice, 'billing_reason') === 'subscription_create') {
            return;
        }

        $attempt = max(1, (int) Arr::get($stripeInvoice, 'attempt_count', 1));
        $next = Arr::get($stripeInvoice, 'next_payment_attempt');

        $this->tell(
            $user,
            BillingDunningNotice::FAILED,
            $invoice->stripe_id,
            $attempt,
            new SubscriptionPaymentFailed($invoice, $attempt, is_numeric($next) ? Carbon::createFromTimestamp((int) $next) : null),
        );
    }

    /**
     * An invoice was paid: if the customer was told it had failed, tell them it has gone through.
     */
    public function paymentSucceeded(?BillingInvoice $invoice): void
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

        $this->tell($user, BillingDunningNotice::RECOVERED, $invoice->stripe_id, 0, new SubscriptionPaymentRecovered($invoice));
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

        $this->tell($user, BillingDunningNotice::CANCELLED, $subscription->stripe_id, 0, new SubscriptionCancelledForNonPayment($plan));
    }

    /**
     * Claim the notice, and send it only if it was ours to claim. Both happen together: if sending fails the claim is undone, so
     * Stripe's next delivery of the same event tries again.
     */
    private function tell(User $user, string $kind, string $reference, int $attempt, Notification $notification): void
    {
        DB::transaction(function () use ($user, $kind, $reference, $attempt, $notification) {
            $claimed = BillingDunningNotice::query()->insertOrIgnore([[
                'user_id' => $user->id,
                'kind' => $kind,
                'reference' => $reference,
                'attempt' => $attempt,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            if ($claimed === 0) {
                return;
            }

            $user->notify($notification);
        });
    }
}
