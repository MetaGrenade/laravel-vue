<?php

namespace App\Payments\Tebex;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProviderSubscription;
use App\Payments\Providers\TebexProvider;

/**
 * Which payment of the shop's a delivery from Tebex is about. Matching is the one thing that must never be guessed
 * (see {@see TebexPayments}), so it is in one place:
 *
 * - **By transaction.** Once a payment has been recorded under Tebex's `tbx-...` id, anything about it later (a
 *   refund, a dispute) is found by that id.
 * - **By the basket's custom data.** The first delivery for a basket carries the order's public id and the nonce the
 *   shop made the basket with, which only the shop and Tebex know. An order id alone is not enough: the nonce must be
 *   the one on that order's payment.
 * - **By the recurring payment.** A renewal is a payment of its own that Tebex makes long after the basket, and names
 *   the recurring payment it belongs to. If the shop already knows that recurring payment, the order that started it is
 *   the order the renewal is for.
 *
 * The last two lead to the order's *first* payment, which is right for a payment that is arriving but wrong for one that
 * is being given back: a refund is about one transaction, and a renewal whose payment the shop never recorded must not
 * be taken for the order's first. So a refund or a dispute is found by its transaction only ({@see self::find()}).
 */
class TebexPaymentMatcher
{
    /**
     * @param  bool  $recordedOnly  Only a payment already recorded under this delivery's transaction id, not the one the
     *                              basket or the recurring payment points to. For refunds and disputes.
     */
    public function find(TebexEvent $event, bool $recordedOnly = false): ?Payment
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

        if ($recordedOnly) {
            return null;
        }

        $custom = $event->basketCustom();

        if ($custom !== null) {
            $order = Order::query()->where('public_id', $custom['order'])->first();

            if ($order !== null) {
                $found = $order->payments()->with('order')
                    ->where('provider', TebexProvider::KEY)
                    ->get()
                    ->first(fn (Payment $payment) => is_string($payment->raw['nonce'] ?? null) && hash_equals($payment->raw['nonce'], $custom['nonce']));

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return $this->throughRecurringPayment($event);
    }

    /**
     * What an order holds, as Tebex's package id => how many, or null when a line cannot be tied to a package (its copy
     * was deleted), in which case nothing can be checked against it.
     *
     * @return array<int, int>|null
     */
    public function ordered(Order $order): ?array
    {
        $order->loadMissing('items.tebexPackage');

        $packages = [];

        foreach ($order->items as $item) {
            if ($item->tebexPackage === null) {
                return null;
            }

            $packages[$item->tebexPackage->tebex_id] = ($packages[$item->tebexPackage->tebex_id] ?? 0) + $item->quantity;
        }

        ksort($packages);

        return $packages === [] ? null : $packages;
    }

    /**
     * The payment that started the order's recurring payment: the order's first Tebex payment, which is the checkout's own
     * (a renewal is only recorded once the recurring payment is known, so it is always a later row).
     */
    private function throughRecurringPayment(TebexEvent $event): ?Payment
    {
        $reference = $event->recurringReference();

        if ($reference === null) {
            return null;
        }

        $order = ProviderSubscription::query()
            ->where('provider', TebexProvider::KEY)
            ->where('reference', $reference)
            ->whereNotNull('order_id')
            ->first()?->order;

        if ($order === null) {
            return null;
        }

        return $order->payments()->with('order')
            ->where('provider', TebexProvider::KEY)
            ->orderBy('id')
            ->first();
    }
}
