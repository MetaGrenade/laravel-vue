<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Notifications\OrderConfirmation;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the customer when payment is confirmed, to the address they gave at
 * checkout (a guest has no account to notify).
 */
class SendOrderConfirmation
{
    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        if (! filled($order->customer_email)) {
            return;
        }

        Notification::route('mail', $order->customer_email)->notify(new OrderConfirmation($order));
    }
}
