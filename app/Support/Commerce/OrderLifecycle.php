<?php

namespace App\Support\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Events\OrderPaid;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place an order changes state. Every method locks the order row,
 * re-reads it, and does nothing if the change has already happened, so a
 * redelivered webhook or a double click cannot apply a change twice.
 */
class OrderLifecycle
{
    public function __construct(private readonly InventoryReserver $inventory) {}

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
                $this->inventory->reserve($order, enforce: false);
                $metadata['late_payment'] = true;

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

            DB::afterCommit(fn () => OrderPaid::dispatch($order));

            return true;
        });
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
     * Cancel an order that has not been paid and give its stock back.
     * Paid orders are never cancelled here; returns false for them.
     */
    public function cancel(Order $order, string $reason = 'cancelled', OrderPaymentStatus $paymentStatus = OrderPaymentStatus::Unpaid): bool
    {
        return DB::transaction(function () use ($order, $reason, $paymentStatus) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Pending || $order->isPaid()) {
                return false;
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
            if ($ordered->product_id === null) {
                continue;
            }

            $line = $cart->items()
                ->where('product_id', $ordered->product_id)
                ->when(
                    $ordered->product_variant_id === null,
                    fn ($query) => $query->whereNull('product_variant_id'),
                    fn ($query) => $query->where('product_variant_id', $ordered->product_variant_id),
                )
                ->first();

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
