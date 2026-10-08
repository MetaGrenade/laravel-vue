<?php

namespace App\Listeners;

use App\Events\OrderFulfilled;
use App\Notifications\OrderShipped;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the customer when staff mark their order fulfilled, unless they chose not to.
 */
class SendOrderShippedNotification
{
    public function handle(OrderFulfilled $event): void
    {
        $order = $event->order;

        if (! $event->notifyCustomer || ! filled($order->customer_email)) {
            return;
        }

        Notification::route('mail', $order->customer_email)->notify(new OrderShipped($order));
    }
}
