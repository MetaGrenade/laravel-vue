<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Tells the customer their order is on its way (or, for an order with nothing to
 * ship, that it is complete), with the tracking details if there are any.
 */
class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return ['mail' => 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $shipment = (array) ($order->metadata['shipment'] ?? []);
        $ships = is_array($order->shipping_address);

        $message = (new MailMessage)
            ->subject($ships ? __('mail.order.shipped.subject_shipped', ['number' => $order->number]) : __('mail.order.shipped.subject_complete', ['number' => $order->number]))
            ->greeting($order->customer_name ? __('mail.order.shipped.greeting_named', ['name' => $order->customer_name]) : __('mail.order.shipped.greeting'))
            ->line($ships ? __('mail.order.shipped.shipped', ['number' => $order->number]) : __('mail.order.shipped.complete', ['number' => $order->number]));

        if (filled($shipment['carrier'] ?? null)) {
            $message->line(__('mail.order.shipped.carrier', ['carrier' => $shipment['carrier']]));
        }

        if (filled($shipment['tracking_number'] ?? null)) {
            $message->line(__('mail.order.shipped.tracking_number', ['number' => $shipment['tracking_number']]));
        }

        $trackingUrl = $shipment['tracking_url'] ?? null;

        return filled($trackingUrl)
            ? $message->action(__('mail.order.shipped.track'), $trackingUrl)
            // Signed, so it works for guests who cannot sign in to see the order.
            : $message->action(__('mail.order.view'), URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
    }
}
