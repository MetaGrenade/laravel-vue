<?php

namespace App\Listeners;

use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Payments\PaymentManager;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * When an unpaid order is cancelled, stop the provider accepting payment for
 * it. Queued: it is a network call and nothing waits on the result.
 */
class ExpireProviderCheckout implements ShouldQueue
{
    public function __construct(private readonly PaymentManager $payments) {}

    public function handle(OrderCancelled $event): void
    {
        $event->order->payments()
            ->where('status', PaymentStatus::Canceled->value)
            ->get()
            ->each(fn ($payment) => $this->payments->provider($payment->provider)->cancelCheckout($payment));
    }
}
