<?php

namespace App\Support\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ExpireOrders;
use App\Jobs\ExpirePendingOrders;
use App\Models\Order;
use App\Payments\PaymentManager;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cancels orders that were never paid and gives their stock back.
 *
 * A customer may have paid just as the hold ran out, with the webhook still on
 * its way, so the provider is asked what happened before an order is cancelled.
 * If it cannot be reached the order is left alone and tried again later:
 * cancelling an order that was paid is worse than holding stock a little longer.
 *
 * Every five minutes {@see ExpirePendingOrders} lists the orders that are due and queues
 * {@see ExpireOrders} for {@see self::CHUNK} of them at a time, so no job makes more than a
 * few provider calls, and an order that cannot be settled stops nobody but itself. An order
 * the provider could not be asked about is not asked again for {@see self::RETRY_AFTER_MINUTES}
 * minutes (it was asked every five minutes, forever, and each failure was reported).
 */
class PendingOrderExpirer
{
    /** Orders in one job: at most this many provider calls, about eight seconds when the provider takes 300 ms to answer. */
    public const CHUNK = 25;

    /** How long an order the provider could not be asked about is left alone, in minutes. */
    public const RETRY_AFTER_MINUTES = 30;

    public function __construct(
        private readonly PaymentManager $payments,
        private readonly OrderLifecycle $lifecycle,
    ) {}

    /**
     * Expire every order that is due, here and now, asking about each: what `php artisan commerce:expire-orders` does. The schedule
     * does not, because one process that asks about thousands of orders in turn is cut off by the worker's timeout.
     *
     * @return array{cancelled: int, paid: int, skipped: int}
     */
    public function run(): array
    {
        $result = ['cancelled' => 0, 'paid' => 0, 'skipped' => 0];
        $after = 0;

        while (($ids = $this->dueOrderIds($after, self::CHUNK)) !== []) {
            $after = max($ids);

            foreach ($this->expireOrders($ids, askAgain: true) as $outcome => $count) {
                $result[$outcome] += $count;
            }
        }

        return $result;
    }

    /**
     * The ids of the orders whose payment window has run out and that are still waiting, after the given id, lowest first.
     *
     * @return list<int>
     */
    public function dueOrderIds(int $after, int $limit): array
    {
        return Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Of these orders, the ones that may be asked about now: not the ones the provider could not be asked about a moment ago.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function readyToAsk(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $waiting = Cache::many(array_map(self::retryKey(...), $ids));

        return array_values(array_filter($ids, fn (int $id): bool => ($waiting[self::retryKey($id)] ?? null) === null));
    }

    /**
     * Expire these orders, one after the other. An order that is no longer waiting (a job that was queued twice, a customer who paid) or
     * whose window has not run out is left out without asking anybody.
     *
     * @param  list<int>  $ids
     * @param  bool  $askAgain  Also the orders the provider could not be asked about lately.
     * @return array{cancelled: int, paid: int, skipped: int}
     */
    public function expireOrders(array $ids, bool $askAgain = false): array
    {
        $result = ['cancelled' => 0, 'paid' => 0, 'skipped' => 0];
        $ready = $askAgain ? $ids : $this->readyToAsk($ids);

        if ($ready === []) {
            return $result;
        }

        $orders = Order::query()
            ->whereKey($ready)
            ->where('status', OrderStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $result[$this->expire($order)]++;
        }

        return $result;
    }

    /**
     * @return 'cancelled'|'paid'|'skipped'
     */
    private function expire(Order $order): string
    {
        try {
            $outcome = $this->settle($order);
        } catch (Throwable $exception) {
            // One order that cannot be settled (the provider, a lock that timed out) must not stop the ones after it.
            report($exception);

            $outcome = 'skipped';
        }

        if ($outcome === 'skipped') {
            Cache::put(self::retryKey($order->id), true, now()->addMinutes(self::RETRY_AFTER_MINUTES));
        }

        return $outcome;
    }

    /**
     * @return 'cancelled'|'paid'|'skipped'
     */
    private function settle(Order $order): string
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

    private static function retryKey(int $orderId): string
    {
        return "commerce:order-expiry:ask-again-after:{$orderId}";
    }
}
