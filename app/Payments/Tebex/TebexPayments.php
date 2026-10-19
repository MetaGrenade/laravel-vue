<?php

namespace App\Payments\Tebex;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\BillingWebhookCall;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Data\ProviderRefund;
use App\Payments\Data\WebhookOutcome;
use App\Payments\Providers\TebexProvider;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use Illuminate\Support\Facades\Log;

/**
 * What the shop does with what Tebex says happened to a payment.
 *
 * Tebex is the merchant of record, so it is told nothing: the shop learns that a basket was paid, what was paid, and
 * what happened to the money afterwards, only from these deliveries. Each is first matched to a payment the shop
 * started, and matching is the one thing that must never be guessed:
 *
 * - **By transaction.** Once a payment has been recorded under Tebex's `tbx-...` id, anything about it later (a refund,
 *   a dispute) is found by that id.
 * - **By the basket's custom data.** The first delivery for a basket carries the order's public id and the nonce the
 *   shop made the basket with ({@see TebexProvider::startCheckout()}), which only the shop and Tebex know. An order id
 *   alone is not enough: the nonce must be the one on that order's payment.
 *
 * A delivery that matches nothing changes nothing. Whether it is a worry depends on what it is, so the call is kept
 * either with a note (a payment that should have been ours, or one of ours that went wrong) or without (something
 * that is not about the shop at all).
 */
class TebexPayments
{
    public function __construct(
        private readonly OrderLifecycle $lifecycle,
        private readonly OrderRefunder $refunds,
    ) {}

    public function apply(TebexEvent $event, BillingWebhookCall $call): WebhookOutcome
    {
        return match (true) {
            $event->type === TebexEvent::PAYMENT_COMPLETED => $this->completed($event, $call),
            $event->type === TebexEvent::PAYMENT_DECLINED => $this->declined($event),
            $event->type === TebexEvent::PAYMENT_REFUNDED => $this->refunded($event, $call),
            $event->isDispute() => $this->dispute($event, $call),
            // Recurring payments (and what they entitle the buyer to) are their own slice.
            $event->isRecurring() => WebhookOutcome::ignored('Recurring payments are not handled yet'),
            default => WebhookOutcome::ignored(),
        };
    }

    private function completed(TebexEvent $event, BillingWebhookCall $call): WebhookOutcome
    {
        $transaction = $event->transactionId();
        $payment = $this->find($event);

        if ($transaction === null) {
            return $this->flag($call, 'The payment has no transaction id, so it was not applied.');
        }

        // A delivery that says it is completed while its payment says otherwise (declined, still pending checkout, a
        // chargeback) is not trusted to have taken any money. A payment that gives no status is taken as it comes.
        if ($event->statusId() !== null && $event->statusId() !== TebexEvent::STATUS_COMPLETE) {
            return $this->flag($call, "Tebex sent a completed payment ({$transaction}) whose status is {$event->statusId()}, not complete, so it was not applied.");
        }

        if ($payment === null) {
            return $this->unmatched($event, $call);
        }

        $order = $payment->order;

        if ($payment->status === PaymentStatus::Succeeded && $payment->provider_payment_id === $transaction) {
            return WebhookOutcome::duplicate();
        }

        // The basket already settled under another transaction: a renewal of a recurring package, or a second payment
        // for the same basket. The order is never applied twice, and the payment row keeps describing the first.
        if (filled($payment->provider_payment_id) && $payment->provider_payment_id !== $transaction) {
            Log::warning('Tebex reported another payment for a basket the shop had already settled.', [
                'order' => $order->number,
                'recorded' => $payment->provider_payment_id,
                'reported' => $transaction,
            ]);

            return $this->flag($call, "Another payment ({$transaction}) was made for an order already paid with {$payment->provider_payment_id}. If it is a renewal of a recurring package, that is not handled yet; otherwise it may need refunding.");
        }

        $paid = $event->paid();

        // Tebex works out the tax and takes its own codes, so the amount may differ from the shop's quote; the currency
        // cannot, since the basket was checked to be in the order's.
        if ($paid === null || $paid->currency !== strtoupper($payment->currency)) {
            $payment->forceFill([
                'status' => PaymentStatus::Review,
                'provider_payment_id' => $transaction,
                'raw' => $this->raw($payment, $event),
            ])->save();

            Log::critical('Tebex reported a payment that does not match the order; it was not applied.', [
                'order' => $order->number,
                'transaction' => $transaction,
                'expected_currency' => $payment->currency,
                'reported' => $paid === null ? null : "{$paid->toDecimal()} {$paid->currency}",
            ]);

            return $this->flag($call, "Tebex took a payment ({$transaction}) in a different currency, or with no amount, than order {$order->number}; it was not applied and needs a person.", WebhookOutcome::handled('Payment needs review'));
        }

        $this->adoptTotal($order, $paid);

        $this->lifecycle->markPaid($order, $payment, [
            'provider_payment_id' => $transaction,
            'amount' => $paid->toDecimal(),
            'raw' => $this->raw($payment, $event),
        ]);

        return WebhookOutcome::handled();
    }

    /**
     * A declined attempt is not the end of the checkout: the buyer can try again in the same basket. It is recorded for
     * staff, and the order is left as it is (it expires if nobody pays).
     */
    private function declined(TebexEvent $event): WebhookOutcome
    {
        $payment = $this->find($event);

        if ($payment === null || $payment->order->isPaid()) {
            return WebhookOutcome::ignored();
        }

        $why = $event->declineMessage();

        OrderEvent::record($payment->order, OrderEvent::NOTE, 'Tebex declined a payment attempt'.($why !== null ? ": {$why}" : '').'. The buyer can try again.', array_filter(['tebex_transaction' => $event->transactionId()]));

        return WebhookOutcome::handled();
    }

    /**
     * Tebex refunds a payment in full, from its panel; the shop records it so the order says so. (The shop cannot ask
     * Tebex to refund: see {@see TebexProvider::capabilities()}.)
     */
    private function refunded(TebexEvent $event, BillingWebhookCall $call): WebhookOutcome
    {
        $status = match ($event->statusId()) {
            TebexEvent::STATUS_REFUND => RefundStatus::Succeeded,
            TebexEvent::STATUS_REFUND_PENDING => RefundStatus::Pending,
            default => null,
        };

        if ($status === null) {
            return WebhookOutcome::ignored('Not a refund Tebex has made');
        }

        $payment = $this->find($event);

        if ($payment === null) {
            return $this->flag($call, 'Tebex refunded a payment the shop has no record of.', WebhookOutcome::ignored('Unknown payment'));
        }

        return $this->takeBack($payment, $event, $call, "{$event->transactionId()}-refund", $status, 'Tebex reports this payment refunded, from the Tebex panel.');
    }

    /**
     * A dispute is the buyer's bank asking for the money back. Staff answer it in the Tebex panel; the shop only keeps
     * them informed, and when it is lost the money is gone, which is a refund the shop did not make.
     */
    private function dispute(TebexEvent $event, BillingWebhookCall $call): WebhookOutcome
    {
        $payment = $this->find($event);

        if ($payment === null) {
            return $this->flag($call, 'Tebex reported a dispute on a payment the shop has no record of.', WebhookOutcome::ignored('Unknown payment'));
        }

        $transaction = (string) $event->transactionId();

        [$text, $warn] = match ($event->type) {
            TebexEvent::DISPUTE_OPENED => ['Tebex reports a dispute (chargeback) has been opened on this payment. It is answered in the Tebex panel.', true],
            TebexEvent::DISPUTE_WON => ['The dispute on this payment was won: the money stays.', false],
            TebexEvent::DISPUTE_LOST => ['The dispute on this payment was lost: the money has been taken back.', true],
            default => ['The dispute on this payment was closed.', false],
        };

        OrderEvent::record($payment->order, OrderEvent::NOTE, $text, ['tebex_transaction' => $transaction]);

        if ($warn) {
            Log::warning($text, ['order' => $payment->order->number, 'transaction' => $transaction]);
        }

        if ($event->type === TebexEvent::DISPUTE_LOST) {
            return $this->takeBack($payment, $event, $call, "{$transaction}-dispute", RefundStatus::Succeeded, null);
        }

        return WebhookOutcome::handled();
    }

    /**
     * Record that the payment was returned, unless the order already says so (a refund made by hand in the shop and then
     * in the Tebex panel is one refund, not two). Tebex returns a payment in full; what the order records is capped at
     * what it still has to refund, so its books never show more than it took.
     */
    private function takeBack(Payment $payment, TebexEvent $event, BillingWebhookCall $call, string $reference, RefundStatus $status, ?string $note): WebhookOutcome
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            return $this->flag($call, "Tebex returned a payment ({$event->transactionId()}) that the shop does not have as paid, so nothing was changed.", WebhookOutcome::ignored('Payment was not applied'));
        }

        $known = Refund::query()->where('provider', TebexProvider::KEY)->where('provider_reference', $reference)->first();
        $amount = Money::parse($payment->amount, $payment->currency);

        if ($known !== null) {
            // The same refund, further along (it was pending and has now gone through).
            $amount = $known->money();
        } else {
            $refundable = $this->refunds->refundable($payment->order);

            if ($refundable->isZero()) {
                OrderEvent::record($payment->order, OrderEvent::NOTE, 'Tebex reports this payment returned; the order already shows it refunded in full, so nothing was added.', ['tebex_transaction' => $event->transactionId()]);

                return WebhookOutcome::handled();
            }

            $amount = Money::ofMinor(min($amount->minor, $refundable->minor), $amount->currency);

            if ($note !== null) {
                OrderEvent::record($payment->order, OrderEvent::NOTE, $note, ['tebex_transaction' => $event->transactionId()]);
            }
        }

        $this->refunds->sync($payment, [
            new ProviderRefund(reference: $reference, amount: $amount, status: $status, reason: 'other'),
        ]);

        return WebhookOutcome::handled();
    }

    /**
     * The payment this delivery is about, or null. See the class comment for how.
     */
    private function find(TebexEvent $event): ?Payment
    {
        $transaction = $event->transactionId();

        if ($transaction !== null) {
            $known = Payment::query()->with('order')
                ->where('provider', TebexProvider::KEY)
                ->where('provider_payment_id', $transaction)
                ->first();

            if ($known !== null && $known->order !== null) {
                return $known;
            }
        }

        $custom = $event->basketCustom();

        if ($custom === null) {
            return null;
        }

        $order = Order::query()->where('public_id', $custom['order'])->first();

        if ($order === null) {
            return null;
        }

        return $order->payments()->with('order')
            ->where('provider', TebexProvider::KEY)
            ->get()
            ->first(fn (Payment $payment) => is_string($payment->raw['nonce'] ?? null) && hash_equals($payment->raw['nonce'], $custom['nonce']));
    }

    /**
     * A payment that is not tied to an order. When it names one (an order and nonce) that is a real problem: money was
     * taken for something the shop cannot find. When it names none it is most likely a sale made outside the shop, on a
     * store that also sells directly, which looks the same.
     */
    private function unmatched(TebexEvent $event, BillingWebhookCall $call): WebhookOutcome
    {
        $custom = $event->basketCustom();

        if ($custom === null) {
            return $this->flag($call, 'Not tied to a shop order: it names no order. A sale made outside the shop looks like this; if a buyer paid through the shop, see "Checked against a real store" in docs/tebex.md.', WebhookOutcome::ignored('Not a shop payment'));
        }

        Log::critical('Tebex reported a payment for an order the shop cannot match.', [
            'transaction' => $event->transactionId(),
            'order' => $custom['order'],
        ]);

        return $this->flag($call, "Tebex took a payment ({$event->transactionId()}) naming order {$custom['order']}, but the shop has no such order, or the nonce is not the one it made. It was not applied and needs a person.", WebhookOutcome::ignored('Unknown order'));
    }

    /**
     * Keep a note on the call, where staff looking at the webhooks will see it, and answer.
     */
    private function flag(BillingWebhookCall $call, string $note, ?WebhookOutcome $outcome = null): WebhookOutcome
    {
        $call->error = $note;

        return $outcome ?? WebhookOutcome::ignored($note);
    }

    /**
     * Make the order say what the buyer really paid. Tebex works out the tax and takes its own codes and gift cards, so
     * the order the shop placed (the packages at their price before tax) can differ from what was charged; more is the
     * tax Tebex added, less is what its codes took off.
     */
    private function adoptTotal(Order $order, Money $paid): void
    {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

        if ($locked->isPaid()) {
            return;
        }

        $quoted = Money::parse($locked->grand_total, $locked->currency);

        if ($paid->equals($quoted)) {
            return;
        }

        $difference = $paid->subtract($quoted);
        $more = $difference->isNegative() ? Money::zero($locked->currency) : $difference;
        $less = $difference->isNegative() ? Money::zero($locked->currency)->subtract($difference) : Money::zero($locked->currency);

        $locked->forceFill([
            'tax_total' => Money::parse($locked->tax_total, $locked->currency)->add($more)->toDecimal(),
            'discount_total' => Money::parse($locked->discount_total, $locked->currency)->add($less)->toDecimal(),
            'grand_total' => $paid->toDecimal(),
        ])->save();

        OrderEvent::record($locked, OrderEvent::NOTE, "Tebex took {$paid->toDecimal()} {$paid->currency}, not the {$quoted->toDecimal()} the shop quoted: it adds tax and applies its own codes, so the order now says what was paid.");
    }

    /**
     * What is kept on the payment: what the shop recorded when the basket was made, with what Tebex has said.
     *
     * @return array<string, mixed>
     */
    private function raw(Payment $payment, TebexEvent $event): array
    {
        $paid = $event->paid();
        $raw = (array) $payment->raw;

        return array_filter([
            ...$raw,
            'transaction_id' => $event->transactionId(),
            'paid' => $paid === null ? null : ['amount' => $paid->toDecimal(), 'currency' => $paid->currency],
            // The account Tebex delivered to, which is the one that counts.
            'buyer' => $event->buyer() ?? ($raw['buyer'] ?? null),
        ], fn ($value) => $value !== null);
    }
}
