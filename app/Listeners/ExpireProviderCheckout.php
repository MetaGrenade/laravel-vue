<?php

namespace App\Listeners;

use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Payments\PaymentManager;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * When an unpaid order is cancelled, make sure the provider stops accepting
 * payment for it. Queued: it is a network call and nothing waits on the result.
 *
 * This is a safety net. Starting a replacement checkout closes the old one
 * itself and confirms it before the replacement is offered, so that case is
 * skipped here. If the provider cannot be reached the job fails and is retried.
 */
class ExpireProviderCheckout implements ShouldQueue
{
    public function __construct(private readonly PaymentManager $payments) {}

    public function handle(OrderCancelled $event): void
    {
        if ($event->reason === 'replaced') {
            return;
        }

        $event->order->payments()
            ->where('status', PaymentStatus::Canceled->value)
            ->get()
            ->each(fn ($payment) => $this->payments->provider($payment->provider)->closeCheckout($payment));
    }
}
