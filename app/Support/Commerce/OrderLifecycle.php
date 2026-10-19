<?php

namespace App\Support\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Events\OrderFulfilled;
use App\Events\OrderPaid;
use App\Models\Cart;
use App\Models\InventoryItem;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Providers\TebexProvider;
use App\Support\Commerce\Digital\DigitalFulfilment;
use App\Support\Commerce\Digital\LicenceKeys;
use App\Support\Commerce\GiftCards\GiftCards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place an order changes state. Every method locks the order row,
 * re-reads it, and does nothing if the change has already happened, so a
 * redelivered webhook or a double click cannot apply a change twice.
 */
class OrderLifecycle
{
    public function __construct(
        private readonly InventoryReserver $inventory,
        private readonly DigitalFulfilment $digital,
        private readonly LicenceKeys $keys,
        private readonly GiftCards $giftCards,
    ) {}

    /**
     * Record that a payment succeeded. Returns false when the order was
     * already paid (nothing changed).
     *
     * @param  array<string, mixed>  $paymentAttributes  Extra fields for the payment row.
     */
    public function markPaid(Order $order, Payment $payment, array $paymentAttributes = []): bool
    {
        return DB::transaction(function () use ($order, $payment, $paymentAttributes) {
            $order = $this->lock($order);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $alreadyRecorded = $payment->status === PaymentStatus::Succeeded;

            $payment->forceFill([
                ...$paymentAttributes,
                'status' => PaymentStatus::Succeeded,
                'paid_at' => $payment->paid_at ?? now(),
            ])->save();

            if ($order->isPaid()) {
                // Either this payment was already applied (a redelivered webhook), or a
                // different attempt also succeeded and a person must refund it. The
                // order itself is never applied twice.
                if (! $alreadyRecorded) {
                    Log::warning('A second payment succeeded for an order that was already paid.', [
                        'order' => $order->number,
                        'payment' => $payment->provider_reference,
                    ]);
                }

                return false;
            }

            $metadata = $order->metadata ?? [];

            if ($order->status === OrderStatus::Cancelled) {
                // The customer paid just as the order was being cancelled. The money is
                // already taken, so honour the order: take the stock back (even if that
                // leaves it negative) and flag it so a person can check.
                //
                // The card's share was given back when the order was cancelled. Take it again, as much as the card
                // still has; what it cannot cover is left for a person. The card goes first, then the stock, the
                // order everything else locks them in, so a checkout using the same card cannot wait on this one.
                $short = $this->giftCards->reapply($order);

                $this->inventory->reserve($order, enforce: false);
                $metadata['late_payment'] = true;

                if (! $short->isZero()) {
                    $metadata['gift_card_short'] = $short->toDecimal();

                    OrderEvent::record($order, OrderEvent::GIFT_CARD_SHORT, "The gift card could not cover its share again: {$short->toDecimal()} {$order->currency} is still to be settled with the customer.");
                }

                Log::warning('Payment arrived for a cancelled order; the order was reinstated.', [
                    'order' => $order->number,
                    'payment' => $payment->provider_reference,
                ]);
            }

            $order->forceFill([
                'status' => OrderStatus::Processing,
                'payment_status' => OrderPaymentStatus::Paid,
                'payment_provider' => $payment->provider,
                'paid_at' => now(),
                'cancelled_at' => null,
                'expires_at' => null,
                'metadata' => $metadata ?: null,
            ])->save();

            $this->consumeCart($order);

            OrderEvent::record($order, OrderEvent::PAID, "Payment of {$payment->amount} {$payment->currency} received".($payment->provider ? ' through '.ucfirst($payment->provider) : ''));

            DB::afterCommit(fn () => OrderPaid::dispatch($order));

            $this->deliver($order);

            return true;
        });
    }

    /**
     * Pay an order that has nothing left to pay: a free product, a discount code that covers all of it, or a gift
     * card that does. There is no payment to record and no provider to ask: the order is simply paid, the same
     * way, and the customer is told. Returns false for an order that is not pending or has something to pay.
     */
    public function markFree(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Pending || $order->isPaid() || ! $order->amountDue()->isZero()) {
                return false;
            }

            $order->forceFill([
                'status' => OrderStatus::Processing,
                'payment_status' => OrderPaymentStatus::Paid,
                'payment_provider' => null,
                'paid_at' => now(),
                'expires_at' => null,
            ])->save();

            $this->consumeCart($order);

            OrderEvent::record($order, OrderEvent::PAID, $order->gift_card_id !== null && (float) $order->gift_card_total > 0
                ? "Paid in full with a gift card ending {$order->giftCard?->last_four}"
                : 'Nothing to pay: the order total is zero');

            DB::afterCommit(fn () => OrderPaid::dispatch($order));

            $this->deliver($order);

            return true;
        });
    }

    /**
     * What a paid order owes in digital goods: the right to download its files and its licence keys, and,
     * when that is all there is to deliver (nothing to ship, nothing to send by hand), completion.
     */
    private function deliver(Order $order): void
    {
        $this->digital->grant($order);
        $this->keys->issue($order);

        // Tebex delivers its packages itself, to the buyer's account in the game, the moment it takes the payment:
        // there is nothing for the shop to ship or for staff to send.
        if ($order->payment_provider === TebexProvider::KEY || $this->digital->isDeliveredInFull($order)) {
            $this->fulfil($order, notifyCustomer: false);
        }
    }

    /**
     * Give a paid order the licence keys it is still owed, for when the pool had none to spare when it
     * was paid (or a key was withdrawn), and complete it if that was all it was waiting for. Returns how
     * many keys were issued.
     */
    public function issueKeys(Order $order, ?User $by = null): int
    {
        return DB::transaction(function () use ($order, $by) {
            $order = $this->lock($order);

            if (! $this->keys->owes($order)) {
                return 0;
            }

            $issued = $this->keys->issue($order, $by);

            if ($issued > 0 && $order->status === OrderStatus::Processing && $this->digital->isDeliveredInFull($order)) {
                $this->fulfil($order, notifyCustomer: false);
            }

            return $issued;
        });
    }

    /**
     * Pass out the keys that have arrived to the paid orders waiting for them, the oldest first, and stop
     * when the pool runs dry. Returns how many keys were issued.
     */
    public function issueWaitingKeys(InventoryItem $stock): int
    {
        $issued = 0;

        foreach ($this->keys->waitingItems($stock)->pluck('order_id')->unique() as $orderId) {
            if (! LicenceKey::query()->where('inventory_item_id', $stock->id)->unused()->exists()) {
                break;
            }

            $issued += $this->issueKeys(Order::query()->findOrFail($orderId));
        }

        return $issued;
    }

    /**
     * The payment attempt failed for good. Frees the stock.
     */
    public function markPaymentFailed(Order $order, Payment $payment): bool
    {
        return DB::transaction(function () use ($order, $payment) {
            Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Failed->value]);

            return $this->cancel($order, 'payment_failed', OrderPaymentStatus::Failed);
        });
    }

    /**
     * Mark a paid order as fulfilled: shipped, or delivered if it is digital. The
     * optional shipment (carrier, tracking number, tracking link) is kept on the
     * order for the customer to see. Returns false when the order is not waiting
     * to be fulfilled (unpaid, cancelled, or already done).
     *
     * @param  array{carrier?: string|null, tracking_number?: string|null, tracking_url?: string|null}  $shipment
     */
    public function fulfil(Order $order, array $shipment = [], bool $notifyCustomer = true, ?User $by = null): bool
    {
        return DB::transaction(function () use ($order, $shipment, $notifyCustomer, $by) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Processing || ! $order->isPaid()) {
                return false;
            }

            $shipment = array_filter($shipment, fn ($value) => filled($value));
            $metadata = $order->metadata ?? [];

            if ($shipment !== []) {
                $metadata['shipment'] = $shipment;
            }

            $order->forceFill([
                'status' => OrderStatus::Completed,
                'fulfilled_at' => now(),
                'metadata' => $metadata ?: null,
            ])->save();

            $detail = trim(($shipment['carrier'] ?? '').' '.($shipment['tracking_number'] ?? ''));

            OrderEvent::record($order, OrderEvent::FULFILLED, 'Marked as fulfilled'.($detail !== '' ? " — {$detail}" : ''), $shipment, $by);

            DB::afterCommit(fn () => OrderFulfilled::dispatch($order, $notifyCustomer));

            return true;
        });
    }

    /**
     * Cancel an order that has not been paid and give its stock back.
     * Paid orders are never cancelled here (they are refunded); returns false for them.
     */
    public function cancel(Order $order, string $reason = 'cancelled', OrderPaymentStatus $paymentStatus = OrderPaymentStatus::Unpaid, ?User $by = null): bool
    {
        return DB::transaction(function () use ($order, $reason, $paymentStatus, $by) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Pending || $order->isPaid()) {
                return false;
            }

            // The card's share first, then the stock: the order in which an order is placed.
            $returned = $this->giftCards->release($order, $reason);

            if (! $returned->isZero()) {
                OrderEvent::record($order, OrderEvent::GIFT_CARD_RELEASED, "{$returned->toDecimal()} {$order->currency} went back onto the gift card");
            }

            $this->inventory->release($order, $reason);

            $order->forceFill([
                'status' => OrderStatus::Cancelled,
                'payment_status' => $paymentStatus,
                'cancelled_at' => now(),
            ])->save();

            Payment::query()
                ->where('order_id', $order->id)
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Canceled->value]);

            OrderEvent::record($order, OrderEvent::CANCELLED, match ($reason) {
                'expired' => 'Cancelled: payment was not completed in time',
                'payment_failed' => 'Cancelled: the payment failed',
                'replaced' => 'Replaced by a newer checkout',
                'cancelled' => 'Cancelled',
                default => "Cancelled: {$reason}",
            }, by: $by);

            DB::afterCommit(fn () => OrderCancelled::dispatch($order, $reason));

            return true;
        });
    }

    private function lock(Order $order): Order
    {
        return Order::query()->lockForUpdate()->findOrFail($order->id);
    }

    /**
     * Take the ordered lines out of the cart they came from.
     *
     * The shopper may have changed the cart while the order waited for payment
     * (the order holds a snapshot from when it was placed), so only what was
     * actually ordered is removed: a line leaves when its whole quantity was
     * bought, and is reduced when more was in the cart. Anything added since
     * stays. The cart is marked converted only once nothing is left in it.
     */
    private function consumeCart(Order $order): void
    {
        $cart = $order->cart_id !== null ? Cart::query()->find($order->cart_id) : null;

        if ($cart === null) {
            return;
        }

        foreach ($order->items as $ordered) {
            // A line is a product (with or without a variant) or a Tebex package, never both.
            $line = match (true) {
                $ordered->product_id !== null => $cart->items()
                    ->where('product_id', $ordered->product_id)
                    ->when(
                        $ordered->product_variant_id === null,
                        fn ($query) => $query->whereNull('product_variant_id'),
                        fn ($query) => $query->where('product_variant_id', $ordered->product_variant_id),
                    )
                    ->first(),
                $ordered->tebex_package_id !== null => $cart->items()->where('tebex_package_id', $ordered->tebex_package_id)->first(),
                default => null,
            };

            if ($line === null) {
                continue;
            }

            $remaining = $line->quantity - $ordered->quantity;

            if ($remaining > 0) {
                CartManager::updateQuantity($line, $remaining);
            } else {
                CartManager::removeItem($line);
            }
        }

        if ($cart->items()->doesntExist()) {
            $cart->forceFill(['status' => CartManager::CONVERTED])->save();
        }
    }
}
