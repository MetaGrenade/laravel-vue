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
            ->subject(__('mail.order.refund.subject', ['number' => $order->number]))
            ->greeting($order->customer_name ? __('mail.order.refund.greeting_named', ['name' => $order->customer_name]) : __('mail.order.refund.greeting'))
            ->line($refund->isGiftCard()
                ? __('mail.order.refund.to_gift_card', ['amount' => $refund->amount, 'currency' => $refund->currency, 'last_four' => $order->giftCard?->last_four, 'number' => $order->number])
                : __('mail.order.refund.refunded', ['amount' => $refund->amount, 'currency' => $refund->currency, 'number' => $order->number]));

        if ($order->payment_status->value === 'refunded') {
            $message->line(__('mail.order.refund.in_full'));
        } else {
            $message->line(__('mail.order.refund.so_far', ['refunded' => $order->refunded_total, 'total' => $order->grand_total, 'currency' => $order->currency]));
        }

        // Money back on a gift card is there at once, and has no statement to show up on.
        if (! $refund->isGiftCard()) {
            $message->line(__('mail.order.refund.statement'));
        }

        return $message
            // Signed, so it works for guests who cannot sign in to see the order.
            ->action(__('mail.order.view'), URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
    }
}
