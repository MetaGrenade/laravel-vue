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
        // Nothing to pay: a free product, or a discount code that covered it all.
        $free = (float) $order->grand_total <= 0;

        $message = (new MailMessage)
            ->subject("Your order {$order->number}")
            ->greeting($order->customer_name ? "Thanks for your order, {$order->customer_name}!" : 'Thanks for your order!')
            ->line($free ? "Your order {$order->number} is confirmed. Nothing was charged." : "We've received your payment for order {$order->number}.");

        foreach ($order->items as $item) {
            $message->line("{$item->quantity} × {$item->description} — {$item->subtotal} {$order->currency}");
        }

        if ((float) $order->shipping_total > 0) {
            $message->line('Shipping'.($order->shipping_method ? " ({$order->shipping_method})" : '').": {$order->shipping_total} {$order->currency}");
        }

        // What a discount code took off (the shipping charge above is shown before it).
        if ((float) $order->discount_total > 0) {
            $message->line('Discount'.($order->coupon_code ? " ({$order->coupon_code})" : '').": -{$order->discount_total} {$order->currency}");
        }

        foreach ((array) ($order->metadata['tax_lines'] ?? []) as $taxLine) {
            $message->line("{$taxLine['name']} ({$taxLine['rate']}%): {$taxLine['amount']} {$order->currency}");
        }

        if (is_array($order->shipping_address)) {
            $message->line('Shipping to: '.$this->describe($order->shipping_address));
        }

        // The links to the files are short-lived and made when the order page is opened, so the email
        // sends the customer there rather than carrying a link that would go stale.
        if ($order->downloadGrants()->exists()) {
            $message->line('Your downloads are on your order page. Open it with the button below whenever you need them.');
        }

        return $message
            ->line($free ? "Total: {$order->grand_total} {$order->currency}" : "Total paid: {$order->grand_total} {$order->currency}")
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
