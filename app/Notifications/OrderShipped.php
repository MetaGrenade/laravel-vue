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
            ->subject($ships ? "Your order {$order->number} has shipped" : "Your order {$order->number} is complete")
            ->greeting($order->customer_name ? "Good news, {$order->customer_name}!" : 'Good news!')
            ->line($ships ? "Order {$order->number} is on its way." : "Order {$order->number} is complete.");

        if (filled($shipment['carrier'] ?? null)) {
            $message->line("Carrier: {$shipment['carrier']}");
        }

        if (filled($shipment['tracking_number'] ?? null)) {
            $message->line("Tracking number: {$shipment['tracking_number']}");
        }

        $trackingUrl = $shipment['tracking_url'] ?? null;

        return filled($trackingUrl)
            ? $message->action('Track your parcel', $trackingUrl)
            // Signed, so it works for guests who cannot sign in to see the order.
            : $message->action('View your order', URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
    }
}
