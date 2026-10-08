<?php

namespace App\Listeners;

use App\Events\OrderRefunded;
use App\Notifications\RefundIssued;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the customer when a refund succeeds, if the refund asked for it. Refunds made
 * in the provider's own dashboard do not: whoever made them is looking after the customer.
 */
class SendRefundNotification
{
    public function handle(OrderRefunded $event): void
    {
        $order = $event->order;

        if (! $event->refund->notify_customer || ! filled($order->customer_email)) {
            return;
        }

        Notification::route('mail', $order->customer_email)->notify(new RefundIssued($order, $event->refund));
    }
}
