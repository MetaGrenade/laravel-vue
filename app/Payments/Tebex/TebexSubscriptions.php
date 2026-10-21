<?php

namespace App\Payments\Tebex;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingWebhookCall;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\ProviderSubscription;
use App\Models\TebexPackage;
use App\Payments\Data\WebhookOutcome;
use App\Payments\Providers\TebexProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * What the shop does with what Tebex says about a recurring payment (a recurring package).
 *
 * Tebex owns it: it charges the customer every period, retries when a charge fails, and ends it, and tells the shop by
 * webhook. The shop keeps a mirror of what it has been told ({@see ProviderSubscription}), so that staff and the
 * customer can see where it stands, and records each renewal as a payment of its own.
 *
 * - **The first payment** is the order's own payment (it is settled like any other, by {@see TebexPayments}); it names
 *   the recurring payment it started, which is when the mirror is made.
 * - **A renewal** is a later payment naming the same recurring payment. It is recorded as a payment of the order's (it is
 *   money the shop took) but never marks the order paid again or changes what the order took in. It is held for review,
 *   like any payment, if it is not for what the order holds.
 * - **What Tebex says about the recurring payment** (started, renewed, ended, cancellation asked for or taken back, its
 *   status changing) updates the mirror. A delivery that is older than one already applied is ignored, since Tebex
 *   retries and nothing promises the order they arrive in.
 *
 * A recurring payment is tied to the order that started it the way any payment is (see {@see TebexPaymentMatcher}), by
 * the first payment it carries. One that cannot be tied to an order (a sale made outside the shop, on a store that also
 * sells directly) is not kept, and the delivery says so.
 */
class TebexSubscriptions
{
    public function __construct(private readonly TebexPaymentMatcher $matcher) {}

    /**
     * Whether a payment row is a renewal (recorded here) and not the order's own payment.
     */
    public function isRenewal(Payment $payment): bool
    {
        return ($payment->raw['renewal'] ?? false) === true;
    }

    /**
     * A `recurring-payment.*` delivery.
     */
    public function apply(TebexEvent $event, BillingWebhookCall $call): WebhookOutcome
    {
        $reference = $event->reference();

        if ($reference === null) {
            $call->error = 'A recurring payment delivery with no reference, so it was not applied.';

            return WebhookOutcome::ignored('No recurring payment named');
        }

        $subscription = $this->find($reference);
        [$order, $initial] = $subscription?->order !== null ? [$subscription->order, null] : $this->orderOf($event);

        if ($subscription === null && $order === null) {
            $call->error = "A recurring payment ({$reference}) that names no shop order. A sale made outside the shop looks like this; if a buyer paid through the shop, see \"Checked against a real store\" in docs/tebex.md.";

            return WebhookOutcome::ignored('Not a shop payment');
        }

        $at = $event->at();

        if ($subscription !== null && $at !== null && $subscription->last_event_at !== null && $at->lt($subscription->last_event_at)) {
            return WebhookOutcome::ignored('Older than what the shop already has');
        }

        $subscription ??= $this->make($reference);

        if ($order !== null) {
            $this->attach($subscription, $order, $initial ?? $event->nestedPayment('initial_payment'));
        }

        $this->fill($subscription, $event);
        $note = $this->applyType($subscription, $event, $at);

        $subscription->last_event_at = $at ?? $subscription->last_event_at;
        $subscription->save();

        if ($note !== null && $subscription->order !== null) {
            OrderEvent::record($subscription->order, OrderEvent::NOTE, $note, ['tebex_recurring_payment' => $reference]);
        }

        return WebhookOutcome::handled();
    }

    /**
     * The first payment of a recurring package has been settled: start keeping track of the recurring payment it began.
     */
    public function started(Payment $payment, TebexEvent $event): void
    {
        $reference = $event->recurringReference();

        if ($reference === null || $payment->order === null) {
            return;
        }

        $subscription = $this->find($reference) ?? $this->make($reference);
        $new = ! $subscription->exists;

        $this->attach($subscription, $payment->order, $event);

        if ($new) {
            $subscription->started_at = now();
            $subscription->last_payment_at = $payment->paid_at ?? now();
            $subscription->amount = $payment->amount;
            $subscription->currency = $payment->currency;
        }

        $subscription->save();
    }

    /**
     * A later payment of a recurring payment: record it as a payment of the order's.
     *
     * Once: a renewal that was recorded is found again by its transaction, and {@see TebexPayments} answers a delivery
     * for one that succeeded before it gets here. What does come here a second time is one that was held for review.
     *
     * @param  Payment  $payment  The order's own payment (found through the recurring payment or the basket's custom data),
     *                            or this renewal's own row when Tebex has sent it before and it was held.
     */
    public function renew(Payment $payment, TebexEvent $event, BillingWebhookCall $call): WebhookOutcome
    {
        $order = $payment->order;
        $transaction = (string) $event->transactionId();
        $reference = $event->recurringReference() ?? (is_string($payment->raw['recurring_payment_reference'] ?? null) ? $payment->raw['recurring_payment_reference'] : null);

        $row = $this->isRenewal($payment) ? $payment : new Payment;
        $paid = $event->paid();

        $problem = match (true) {
            $paid === null || $paid->currency !== strtoupper($order->currency) => 'in a different currency, or with no amount,',
            ! $this->withinOrder($order, $event) => 'for packages that are not what the order holds',
            default => null,
        };

        $moment = $event->moment('settled_at') ?? $event->moment('created_at') ?? Carbon::now();

        $row->forceFill([
            'order_id' => $order->id,
            'owner_type' => $order->owner_type,
            'owner_id' => $order->owner_id,
            'provider' => TebexProvider::KEY,
            'provider_reference' => $transaction,
            'provider_payment_id' => $transaction,
            'status' => $problem === null ? PaymentStatus::Succeeded : PaymentStatus::Review,
            'amount' => $paid?->toDecimal() ?? '0.00',
            'currency' => strtoupper($order->currency),
            'paid_at' => $problem === null ? $moment : null,
            'raw' => array_filter([
                'renewal' => true,
                'transaction_id' => $transaction,
                'recurring_payment_reference' => $reference,
                'paid' => $paid === null ? null : ['amount' => $paid->toDecimal(), 'currency' => $paid->currency],
                'purchased' => $event->purchased(),
            ], fn ($value) => $value !== null),
        ])->save();

        if ($problem !== null) {
            Log::critical('Tebex reported a renewal that does not match the order; it was not applied.', [
                'order' => $order->number,
                'transaction' => $transaction,
                'recurring_payment' => $reference,
            ]);

            $call->error = "Tebex took a renewal ({$transaction}) {$problem} of order {$order->number}; it was recorded for review and needs a person.";

            return WebhookOutcome::handled('Renewal needs review');
        }

        if ($reference !== null) {
            $subscription = $this->find($reference) ?? $this->make($reference);
            $this->attach($subscription, $order, $event);
            $subscription->renewals_count++;
            $subscription->last_payment_at = $moment;
            $subscription->save();
        }

        OrderEvent::record($order, OrderEvent::NOTE, "A renewal payment of {$row->amount} {$row->currency} was received through Tebex.", array_filter(['tebex_transaction' => $transaction, 'tebex_recurring_payment' => $reference]));

        return WebhookOutcome::handled();
    }

    /**
     * Tebex gave a renewal back (refunded it, or took it in a dispute that was lost). It is money the order did not take
     * in, so what the order shows as refunded does not change: it is noted for whoever answers to the customer.
     */
    public function returned(Payment $payment, TebexEvent $event, string $what): WebhookOutcome
    {
        $raw = (array) $payment->raw;

        if (($raw['returned'] ?? null) === $what) {
            return WebhookOutcome::handled();
        }

        $payment->forceFill(['raw' => [...$raw, 'returned' => $what]])->save();

        OrderEvent::record($payment->order, OrderEvent::NOTE, "A renewal payment ({$event->transactionId()}) {$what} at Tebex. It is not part of what the order took in, so the order's refunds are unchanged.", ['tebex_transaction' => $event->transactionId()]);

        Log::warning('A renewal payment was given back at Tebex.', ['order' => $payment->order->number, 'transaction' => $event->transactionId(), 'what' => $what]);

        return WebhookOutcome::handled();
    }

    private function find(string $reference): ?ProviderSubscription
    {
        return ProviderSubscription::query()->where('provider', TebexProvider::KEY)->where('reference', $reference)->first();
    }

    private function make(string $reference): ProviderSubscription
    {
        return new ProviderSubscription(['provider' => TebexProvider::KEY, 'reference' => $reference, 'status' => SubscriptionStatus::Active]);
    }

    /**
     * The order a recurring payment's own payments say it belongs to, and the payment that said so.
     *
     * @return array{0: Order|null, 1: TebexEvent|null}
     */
    private function orderOf(TebexEvent $event): array
    {
        foreach (['initial_payment', 'last_payment'] as $key) {
            $nested = $event->nestedPayment($key);
            $found = $nested === null ? null : $this->matcher->find($nested);

            if ($found !== null && $found->order !== null) {
                return [$found->order, $nested];
            }
        }

        return [null, null];
    }

    /**
     * Tie it to the order that started it, once: whoever owns the order owns it, and the package is the recurring one.
     */
    private function attach(ProviderSubscription $subscription, Order $order, ?TebexEvent $payment): void
    {
        if ($subscription->order_id === null) {
            $subscription->order_id = $order->id;
            $subscription->owner_type = $order->owner_type;
            $subscription->owner_id = $order->owner_id;
        }

        if ($subscription->tebex_package_id === null) {
            $package = $this->packageOf($order, $payment);

            if ($package !== null) {
                $subscription->tebex_package_id = $package->id;
                $subscription->package_name = $package->name;
            }
        }
    }

    /**
     * The package a recurring payment is for: the recurring one among what was bought (or among what the order holds,
     * when the delivery does not say).
     */
    private function packageOf(Order $order, ?TebexEvent $payment): ?TebexPackage
    {
        $ids = array_keys($payment?->purchased() ?? $this->matcher->ordered($order) ?? []);

        if ($ids === []) {
            return null;
        }

        return TebexPackage::query()->whereIn('tebex_id', $ids)->where('type', TebexPackage::SUBSCRIPTION)->orderBy('id')->first()
            ?? TebexPackage::query()->whereIn('tebex_id', $ids)->orderBy('id')->first();
    }

    /**
     * What the delivery says about the recurring payment, put on the mirror.
     */
    private function fill(ProviderSubscription $subscription, TebexEvent $event): void
    {
        $status = SubscriptionStatus::fromTebex($event->statusId());

        if ($status !== null) {
            $subscription->status = $status;
        }

        $subscription->provider_status = $event->statusDescription() ?? $subscription->provider_status;
        $price = $event->price();

        if ($price !== null) {
            $subscription->amount = $price->toDecimal();
            $subscription->currency = $price->currency;
        }

        $subscription->fail_count = $event->failCount();
        $subscription->started_at ??= $event->moment('created_at');
        $subscription->last_payment_at = $event->moment('last_payment.created_at') ?? $subscription->last_payment_at;

        // These are given as null when there is nothing (a recurring payment that has ended has no next payment).
        if (array_key_exists('next_payment_at', $event->subject)) {
            $subscription->next_payment_at = $event->moment('next_payment_at');
        }

        if (array_key_exists('cancelled_at', $event->subject)) {
            $subscription->cancelled_at = $event->moment('cancelled_at');
        }

        $subscription->cancel_reason = $event->cancelReason() ?? $subscription->cancel_reason;
        $subscription->raw = $event->redacted()['subject'];
    }

    /**
     * What this kind of delivery means for the recurring payment, and what to say about it on the order (or null for
     * nothing worth saying).
     */
    private function applyType(ProviderSubscription $subscription, TebexEvent $event, ?Carbon $at): ?string
    {
        $name = $subscription->package_name !== null ? " for {$subscription->package_name}" : '';

        return match ($event->type) {
            'recurring-payment.started' => "A recurring payment{$name} was started at Tebex ({$subscription->reference}).",
            'recurring-payment.ended' => $this->ended($subscription, $at, $name),
            'recurring-payment.cancellation.requested' => $this->cancellationRequested($subscription, $at, $name),
            'recurring-payment.cancellation.aborted' => $this->cancellationAborted($subscription, $name),
            default => $subscription->status === SubscriptionStatus::Overdue && $subscription->fail_count > 0
                ? "A renewal{$name} could not be taken ({$subscription->fail_count} ".($subscription->fail_count === 1 ? 'try' : 'tries').'); Tebex will try again.'
                : null,
        };
    }

    private function ended(ProviderSubscription $subscription, ?Carbon $at, string $name): string
    {
        $subscription->ended_at ??= $at ?? Carbon::now();
        $subscription->next_payment_at = null;

        return "The recurring payment{$name} has ended ({$subscription->provider_status}).";
    }

    private function cancellationRequested(ProviderSubscription $subscription, ?Carbon $at, string $name): string
    {
        $subscription->cancellation_requested_at = $at ?? Carbon::now();

        return "Cancelling the recurring payment{$name} was asked for. It runs until the end of what has been paid for.";
    }

    private function cancellationAborted(ProviderSubscription $subscription, string $name): string
    {
        $subscription->cancellation_requested_at = null;

        return "The request to cancel the recurring payment{$name} was taken back. It renews as normal.";
    }

    /**
     * Whether what a payment lists is something the order holds: nothing it does not hold, and no more of anything than
     * it does. (A renewal need not list everything the order has, since only the recurring package is renewed.)
     */
    private function withinOrder(Order $order, TebexEvent $event): bool
    {
        $ordered = $this->matcher->ordered($order);
        $bought = $event->purchased();

        if ($ordered === null || $bought === null) {
            return false;
        }

        foreach ($bought as $id => $quantity) {
            if (($ordered[$id] ?? 0) < $quantity) {
                return false;
            }
        }

        return true;
    }
}
