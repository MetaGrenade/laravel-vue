<?php

namespace App\Support\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Events\OrderRefunded;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Payments\Capability;
use App\Payments\Data\ProviderRefund;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentManager;
use App\Support\Commerce\Digital\DigitalFulfilment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place a refund is created or changes state, and the only place that
 * turns refunds into a payment status on the order.
 *
 * A refund starts as a pending record, then the provider is asked, then the
 * answer is applied. The record comes first and is committed before the network
 * call, so a crash or a lost reply leaves a refund the provider can be asked
 * about instead of money that moved with no trace. The order's refunded total
 * is never edited: it is recomputed from the succeeded refunds every time one
 * changes, so repeating or reordering provider messages cannot drift it.
 */
class OrderRefunder
{
    /**
     * Set in the order's metadata when a full refund cancelled it, so that if that refund
     * later fails the order can be told apart from one cancelled for another reason.
     */
    private const CANCELLED_BY_REFUND = 'cancelled_by_refund';

    /**
     * A refund the provider has no record of after this long never reached it.
     */
    private const UNCONFIRMED_AFTER_MINUTES = 10;

    public function __construct(
        private readonly PaymentManager $payments,
        private readonly InventoryReserver $inventory,
        private readonly DigitalFulfilment $digital,
    ) {}

    /**
     * How much of the order can still be refunded: its total less the refunds
     * that succeeded and those still pending (which may yet succeed).
     */
    public function refundable(Order $order): Money
    {
        $total = Money::parse($order->grand_total, $order->currency);

        $held = Refund::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Succeeded->value])
            ->get(['amount', 'currency'])
            ->reduce(fn (Money $carry, Refund $refund) => $carry->add($refund->money()), Money::zero($order->currency));

        $remaining = $total->subtract($held);

        return $remaining->isNegative() ? Money::zero($order->currency) : $remaining;
    }

    /**
     * The payment that paid for the order: the first one to succeed. A customer who
     * paid twice has a second succeeded payment, which is a matter for a person and
     * is never counted as a refund of the order.
     */
    public function primaryPayment(Order $order): ?Payment
    {
        return $order->payments()
            ->where('status', PaymentStatus::Succeeded->value)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Refund part or all of a paid order.
     *
     * Returns the refund in whatever state it reached: succeeded, failed, or
     * still pending when the provider has not confirmed (check `status`).
     *
     * @throws RefundException When the refund is not allowed.
     */
    public function request(Order $order, Money $amount, RefundRequest $options, ?User $by = null): Refund
    {
        [$refund, $isNew] = DB::transaction(fn () => $this->record($order, $amount, $options, $by));

        if ($refund->status !== RefundStatus::Pending) {
            return $refund;
        }

        if (! $refund->isProviderRefund()) {
            // Refunded by hand outside the shop: nothing to ask anyone.
            return $this->settle($refund, RefundStatus::Succeeded);
        }

        // A repeated submission whose first attempt never got an answer asks again; the
        // idempotency key makes that safe. One that already has a reference is left to the
        // provider's own messages.
        if ($isNew || $refund->provider_reference === null) {
            return $this->askProvider($refund);
        }

        return $refund;
    }

    /**
     * Ask the provider what became of a pending refund and apply the answer.
     *
     * @throws PaymentException When the provider cannot be asked.
     */
    public function check(Refund $refund): Refund
    {
        if ($refund->status !== RefundStatus::Pending || ! $refund->isProviderRefund()) {
            return $refund;
        }

        $payment = $refund->payment ?? throw new PaymentException('This refund has no payment to look up.');

        $this->payments->provider((string) $refund->provider)->syncRefunds($payment);

        $refund->refresh();

        // The provider lists everything it holds for the payment. A request it never received is
        // not among them, and after the time a request could still be in flight it never will be.
        if (
            $refund->status === RefundStatus::Pending
            && $refund->provider_reference === null
            && $refund->created_at !== null
            && $refund->created_at->lte(now()->subMinutes(self::UNCONFIRMED_AFTER_MINUTES))
        ) {
            return $this->settle($refund, RefundStatus::Failed, null, 'The provider has no record of this refund, so it never reached it.');
        }

        return $refund;
    }

    /**
     * Bring the shop's refunds for a payment in line with what the provider reports.
     *
     * @param  list<ProviderRefund>  $remote  Every refund the provider holds for the payment.
     */
    public function sync(Payment $payment, array $remote): void
    {
        $order = $payment->order;

        if ($order === null || $payment->status !== PaymentStatus::Succeeded) {
            Log::warning('Refunds were reported for a payment that is not recorded as paid; they were not applied.', [
                'payment' => $payment->provider_reference,
            ]);

            return;
        }

        $primary = $this->primaryPayment($order);

        foreach ($remote as $item) {
            $refund = Refund::query()
                ->where('provider', $payment->provider)
                ->where('provider_reference', $item->reference)
                ->first();

            // A refund the shop asked for whose reply was lost: recognise it by the id it was sent with.
            if ($refund === null && $item->refundId !== null) {
                $refund = Refund::query()
                    ->whereKey($item->refundId)
                    ->where('order_id', $order->id)
                    ->whereNull('provider_reference')
                    ->first();
            }

            if ($refund === null) {
                if ($primary === null || $primary->id !== $payment->id) {
                    Log::warning('A refund was made on a duplicate payment; it was not applied to the order.', [
                        'order' => $order->number,
                        'refund' => $item->reference,
                    ]);

                    continue;
                }

                $refund = $this->adopt($payment, $item);

                if ($refund === null) {
                    continue;
                }
            }

            $this->settle($refund, $item->status, $item->reference, $item->failureReason);
        }
    }

    /**
     * Record a new state for a refund and bring the order in line. Safe to call
     * again with the same answer: nothing changes the second time.
     */
    public function settle(Refund $refund, RefundStatus $status, ?string $reference = null, ?string $failureReason = null): Refund
    {
        DB::transaction(function () use ($refund, $status, $reference, $failureReason) {
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            $reference ??= $locked->provider_reference;
            $failureReason = $status === RefundStatus::Succeeded ? null : $failureReason;

            if (
                $locked->status === $status
                && $locked->provider_reference === $reference
                && $locked->failure_reason === $failureReason
            ) {
                return;
            }

            $firstSuccess = $status === RefundStatus::Succeeded && $locked->processed_at === null;
            $changed = $locked->status !== $status;

            $locked->forceFill([
                'status' => $status,
                'provider_reference' => $reference,
                'failure_reason' => $failureReason,
                'processed_at' => $firstSuccess ? now() : $locked->processed_at,
            ])->save();

            $this->recalculate($order);

            if ($status === RefundStatus::Succeeded) {
                if ($firstSuccess) {
                    $this->recordEvent(
                        $order,
                        $locked,
                        OrderEvent::REFUND_SUCCEEDED,
                        "Refunded {$locked->amount} {$locked->currency}".($locked->isProviderRefund() ? '' : ' (recorded by hand)'),
                    );
                } elseif ($changed) {
                    $this->recordEvent($order, $locked, OrderEvent::REFUND_SUCCEEDED, "Refund of {$locked->amount} {$locked->currency} went through after all");
                }

                // Every time, not just the first: a refund that failed and then succeeded again has
                // cancelled the order again. Only what the order still holds is returned.
                if ($locked->restock && $order->payment_status === OrderPaymentStatus::Refunded) {
                    $this->inventory->restock($order, "Refund {$locked->id}");
                }

                if ($firstSuccess) {
                    DB::afterCommit(fn () => OrderRefunded::dispatch($order, $locked));
                }
            } elseif ($changed && in_array($status, [RefundStatus::Failed, RefundStatus::Canceled], true)) {
                $this->recordEvent(
                    $order,
                    $locked,
                    OrderEvent::REFUND_FAILED,
                    "Refund of {$locked->amount} {$locked->currency} ".($status === RefundStatus::Failed ? 'failed' : 'was canceled').($failureReason ? ": {$failureReason}" : ''),
                );
            }
        });

        return $refund->refresh();
    }

    /**
     * Create the pending record, once per submission.
     *
     * @return array{0: Refund, 1: bool} The refund and whether this call created it.
     */
    private function record(Order $order, Money $amount, RefundRequest $options, ?User $by): array
    {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
        $key = 'refund-'.$options->token;

        $existing = Refund::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            if ($existing->order_id !== $locked->id) {
                throw new RefundException('That refund request has already been used. Reload the page and try again.');
            }

            return [$existing, false];
        }

        if (! $locked->isPaid()) {
            throw new RefundException('Only an order that has been paid can be refunded.');
        }

        if ($amount->currency !== strtoupper($locked->currency)) {
            throw new RefundException("This order is in {$locked->currency}; the refund must be too.");
        }

        if ($amount->minor <= 0) {
            throw new RefundException('Enter an amount greater than zero.');
        }

        $refundable = $this->refundable($locked);

        if ($amount->minor > $refundable->minor) {
            throw new RefundException($refundable->isZero()
                ? 'There is nothing left to refund on this order.'
                : "At most {$refundable->toDecimal()} {$locked->currency} can still be refunded.");
        }

        if ($options->restock && ! $amount->equals($refundable)) {
            throw new RefundException('Items can only go back into stock when this refund completes the order.', 'restock');
        }

        $payment = $this->primaryPayment($locked);
        $provider = null;

        if (! $options->manual) {
            if (
                $payment === null
                || blank($payment->provider_payment_id)
                || ! in_array(Capability::REFUNDS, $this->payments->provider($payment->provider)->capabilities(), true)
            ) {
                throw new RefundException('The provider this order was paid through cannot refund it from here. If you returned the money another way, record the refund instead.');
            }

            $provider = $payment->provider;
        }

        $refund = Refund::create([
            'order_id' => $locked->id,
            'payment_id' => $payment?->id,
            'user_id' => $by?->id,
            'provider' => $provider,
            'idempotency_key' => $key,
            'status' => RefundStatus::Pending,
            'amount' => $amount->toDecimal(),
            'currency' => $amount->currency,
            'reason' => $options->reason,
            'note' => $options->note,
            'restock' => $options->restock,
            'notify_customer' => $options->notifyCustomer,
        ]);

        // A refund recorded by hand succeeds at once, and says so then; only one that has to be
        // asked of the provider has a moment of being requested.
        if (! $options->manual) {
            $this->recordEvent(
                $locked,
                $refund,
                OrderEvent::REFUND_REQUESTED,
                "Refund requested: {$refund->amount} {$refund->currency}".($options->note ? " — {$options->note}" : ''),
            );
        }

        return [$refund, true];
    }

    /**
     * Send the request to the provider and apply its answer.
     */
    private function askProvider(Refund $refund): Refund
    {
        try {
            $outcome = $this->payments->provider((string) $refund->provider)->refund($refund);
        } catch (PaymentException $exception) {
            // The refund may exist at the provider. Leave it pending: it is found again by
            // "check", or by the provider's own messages, and never guessed to have failed.
            report($exception);

            $order = $refund->order()->firstOrFail();
            $this->recordEvent($order, $refund, OrderEvent::REFUND_UNCONFIRMED, 'The provider did not confirm the refund; it is pending until it is checked.');

            return $refund->refresh();
        }

        return $this->settle($refund, $outcome->status, $outcome->reference, $outcome->failureReason);
    }

    /**
     * Start tracking a refund that was made at the provider rather than through the shop.
     */
    private function adopt(Payment $payment, ProviderRefund $item): ?Refund
    {
        $order = $payment->order;

        if ($order === null || $item->amount->currency !== strtoupper($order->currency)) {
            Log::warning('A refund in a different currency than its order was not applied.', ['refund' => $item->reference]);

            return null;
        }

        $attributes = [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'provider' => $payment->provider,
            'provider_reference' => $item->reference,
            'idempotency_key' => 'external-'.sha1($payment->provider.'|'.$item->reference),
            'status' => RefundStatus::Pending,
            'amount' => $item->amount->toDecimal(),
            'currency' => $item->amount->currency,
            'reason' => $item->reason,
            'restock' => false,
            // Whoever refunded it at the provider is looking after the customer.
            'notify_customer' => false,
        ];

        try {
            return Refund::create($attributes);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery adopted it first.
            return Refund::query()->where('idempotency_key', $attributes['idempotency_key'])->firstOrFail();
        }
    }

    /**
     * Recompute what the order shows from its succeeded refunds.
     */
    private function recalculate(Order $order): void
    {
        $refunded = Refund::query()
            ->where('order_id', $order->id)
            ->where('status', RefundStatus::Succeeded->value)
            ->get(['amount', 'currency'])
            ->reduce(fn (Money $carry, Refund $refund) => $carry->add($refund->money()), Money::zero($order->currency));

        $total = Money::parse($order->grand_total, $order->currency);

        $paymentStatus = match (true) {
            $refunded->isZero() => OrderPaymentStatus::Paid,
            $refunded->minor >= $total->minor => OrderPaymentStatus::Refunded,
            default => OrderPaymentStatus::PartiallyRefunded,
        };

        $attributes = ['refunded_total' => $refunded->toDecimal()];
        $metadata = $order->metadata ?? [];

        if ($order->isPaid()) {
            $attributes['payment_status'] = $paymentStatus;
        }

        if ($paymentStatus === OrderPaymentStatus::Refunded && $order->status === OrderStatus::Processing) {
            // Nothing left to ship: an order refunded in full before it went out is cancelled.
            $attributes['status'] = OrderStatus::Cancelled;
            $attributes['cancelled_at'] = now();
            $attributes['metadata'] = [...$metadata, self::CANCELLED_BY_REFUND => true];

            OrderEvent::record($order, OrderEvent::CANCELLED, 'Cancelled: refunded in full before it was fulfilled.');
        } elseif (
            $paymentStatus !== OrderPaymentStatus::Refunded
            && $order->isPaid()
            && $order->status === OrderStatus::Cancelled
            && ($metadata[self::CANCELLED_BY_REFUND] ?? false)
        ) {
            // The refund that cancelled the order did not hold (a refund can succeed and then fail).
            // The customer's money was never returned, so the order is owed again: back to processing
            // with its stock taken again, rather than left cancelled with the goods up for sale.
            unset($metadata[self::CANCELLED_BY_REFUND]);

            $attributes['status'] = OrderStatus::Processing;
            $attributes['cancelled_at'] = null;
            $attributes['metadata'] = $metadata ?: null;

            $this->inventory->takeBack($order, 'Refund did not hold');

            OrderEvent::record($order, OrderEvent::REINSTATED, 'Back to processing: the refund that cancelled this order did not go through. Refund it again, or contact the customer.');

            Log::warning('A refund that had cancelled an order did not hold; the order was put back to processing.', ['order' => $order->number]);
        }

        $order->forceFill($attributes)->save();

        // Downloads follow the money: they end with a refund in full, and return if that refund fails.
        $this->digital->syncRevocation($order);
    }

    private function recordEvent(Order $order, Refund $refund, string $type, string $message): void
    {
        OrderEvent::record($order, $type, $message, ['refund_id' => $refund->id], $refund->user_id);
    }
}
