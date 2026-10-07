<?php

namespace App\Support\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Payments\PaymentManager;
use Throwable;

/**
 * Cancels orders that were never paid and gives their stock back.
 *
 * A customer may have paid just as the hold ran out, with the webhook still on
 * its way, so the provider is asked what happened before an order is cancelled.
 * If it cannot be reached the order is left alone and tried again next time:
 * cancelling an order that was paid is worse than holding stock a little longer.
 */
class PendingOrderExpirer
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly OrderLifecycle $lifecycle,
    ) {}

    /**
     * @return array{cancelled: int, paid: int, skipped: int}
     */
    public function run(): array
    {
        $result = ['cancelled' => 0, 'paid' => 0, 'skipped' => 0];

        Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($orders) use (&$result) {
                foreach ($orders as $order) {
                    $result[$this->expire($order)]++;
                }
            });

        return $result;
    }

    /**
     * @return 'cancelled'|'paid'|'skipped'
     */
    private function expire(Order $order): string
    {
        $payment = $order->payments()->latest('id')->first();

        if ($payment !== null && $payment->status === PaymentStatus::Pending) {
            try {
                $this->payments->provider($payment->provider)->reconcile($payment);
            } catch (Throwable $exception) {
                report($exception);

                return 'skipped';
            }

            $order->refresh();

            if ($order->status !== OrderStatus::Pending) {
                return $order->isPaid() ? 'paid' : 'cancelled';
            }
        }

        return $this->lifecycle->cancel($order, 'expired') ? 'cancelled' : 'skipped';
    }
}
