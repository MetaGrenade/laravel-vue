<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Commerce\Countries;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class OrderConfirmation extends Notification implements ShouldQueue
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
        $order = $this->order->loadMissing('items');

        $message = (new MailMessage)
            ->subject("Your order {$order->number}")
            ->greeting($order->customer_name ? "Thanks for your order, {$order->customer_name}!" : 'Thanks for your order!')
            ->line("We've received your payment for order {$order->number}.");

        foreach ($order->items as $item) {
            $message->line("{$item->quantity} × {$item->description} — {$item->subtotal} {$order->currency}");
        }

        if ((float) $order->shipping_total > 0) {
            $message->line('Shipping'.($order->shipping_method ? " ({$order->shipping_method})" : '').": {$order->shipping_total} {$order->currency}");
        }

        foreach ((array) ($order->metadata['tax_lines'] ?? []) as $taxLine) {
            $message->line("{$taxLine['name']} ({$taxLine['rate']}%): {$taxLine['amount']} {$order->currency}");
        }

        if (is_array($order->shipping_address)) {
            $message->line('Shipping to: '.$this->describe($order->shipping_address));
        }

        return $message
            ->line("Total paid: {$order->grand_total} {$order->currency}")
            // Signed, so it works for guests who cannot sign in to see the order.
            ->action('View your order', URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
    }

    /**
     * An address on one line, for an email.
     *
     * @param  array<string, mixed>  $address
     */
    private function describe(array $address): string
    {
        $country = isset($address['country']) ? Countries::name((string) $address['country']) : null;

        return collect([
            $address['name'] ?? null,
            $address['company'] ?? null,
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            $address['city'] ?? null,
            $address['region'] ?? null,
            $address['postal_code'] ?? null,
            $country,
        ])->filter(fn ($part) => filled($part))->implode(', ');
    }
}
