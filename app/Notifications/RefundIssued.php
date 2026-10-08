<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Tells the customer money is on its way back to them.
 */
class RefundIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
        public readonly Refund $refund,
    ) {}

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
        $refund = $this->refund;

        $message = (new MailMessage)
            ->subject("Your refund for order {$order->number}")
            ->greeting($order->customer_name ? "Hello {$order->customer_name}," : 'Hello,')
            ->line("We've refunded {$refund->amount} {$refund->currency} for order {$order->number}.");

        if ($order->payment_status->value === 'refunded') {
            $message->line('Your order has now been refunded in full.');
        } else {
            $message->line("Refunded so far: {$order->refunded_total} {$order->currency} of {$order->grand_total} {$order->currency}.");
        }

        return $message
            ->line('Depending on your bank, it can take several days for the money to show on your statement.')
            // Signed, so it works for guests who cannot sign in to see the order.
            ->action('View your order', URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
    }
}
