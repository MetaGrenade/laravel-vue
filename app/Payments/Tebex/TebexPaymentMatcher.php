<?php

namespace App\Payments\Tebex;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProviderSubscription;
use App\Payments\Providers\TebexProvider;
use App\Support\Commerce\Money;

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
     * Whether the order is of the shop's own products, which Tebex took the payment for through its Checkout API, rather
     * than of Tebex's packages.
     */
    public function soldAsProducts(Order $order): bool
    {
        $order->loadMissing('items');

        return $order->items->contains(fn (OrderItem $item) => $item->product_id !== null);
    }

    /**
     * Why a payment is not for exactly what the order holds, or null if it is. Tebex warns that a webhook holds what was
     * actually bought, so an order that the shop priced itself is only settled by a payment for **the same lines**, each
     * in the quantity ordered and at the price ordered.
     *
     * The price is Tebex's `base_price` of the product, in the order's currency, and **it must be there**. Whether it is for
     * one unit or for the whole row is not in Tebex's documentation (its example has a quantity of one), so a price is
     * accepted if it is either; one that is neither, one that is missing and one in another currency are not.
     */
    public function linesProblem(Order $order, TebexEvent $event): ?string
    {
        $order->loadMissing('items');

        $bought = $event->purchasedLines();

        if ($bought === null) {
            return 'the payment does not say which of the order\'s lines each item is for';
        }

        $ordered = $order->items->sortBy('id')->keyBy('id');

        if (array_keys($bought) !== $ordered->keys()->all()) {
            return 'it lists lines the order does not hold, or leaves some of its lines out';
        }

        foreach ($ordered as $id => $item) {
            $line = $bought[$id];

            if ($line['quantity'] !== $item->quantity) {
                return "line {$id}: {$line['quantity']} bought, {$item->quantity} ordered";
            }

            // A price that is missing, or cannot be read, does not match. The total paid is allowed to differ from the order's
            // (Tebex adds the tax and applies its own codes), so the price of each line is what says the basket was not changed
            // after the shop made it: a payment must not settle the order by naming the right lines and leaving the price out.
            // (The price is null whenever its currency is: see {@see TebexEvent::purchasedLines()}.)
            if ($line['price'] === null) {
                return "line {$id}: no readable price before tax was reported";
            }

            if ($line['currency'] !== strtoupper($order->currency)) {
                return "line {$id}: priced in {$line['currency']}, not {$order->currency}";
            }

            $unit = Money::parse($item->unit_price, $order->currency);

            if ($line['price'] !== $unit->toDecimal() && $line['price'] !== $unit->multiply($item->quantity)->toDecimal()) {
                return "line {$id}: {$line['price']} reported before tax, {$unit->toDecimal()} each ordered";
            }
        }

        return null;
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
