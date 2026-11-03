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
        // Paid for with a gift card, with or without a card payment for the rest.
        $giftCardTotal = (float) $order->gift_card_total;
        $coveredByGiftCard = $giftCardTotal > 0 && $order->amountDue()->isZero();

        $message = (new MailMessage)
            ->subject(__('mail.order.confirmation.subject', ['number' => $order->number]))
            ->greeting($order->customer_name ? __('mail.order.confirmation.greeting_named', ['name' => $order->customer_name]) : __('mail.order.confirmation.greeting'))
            ->line(match (true) {
                $free => __('mail.order.confirmation.confirmed_free', ['number' => $order->number]),
                $coveredByGiftCard => __('mail.order.confirmation.confirmed_gift_card', ['number' => $order->number]),
                default => __('mail.order.confirmation.payment_received', ['number' => $order->number]),
            });

        foreach ($order->items as $item) {
            $message->line(__('mail.order.confirmation.item', ['quantity' => $item->quantity, 'description' => $item->description, 'subtotal' => $item->subtotal, 'currency' => $order->currency]));
        }

        if ((float) $order->shipping_total > 0) {
            $message->line($order->shipping_method
                ? __('mail.order.confirmation.shipping_method', ['method' => $order->shipping_method, 'amount' => $order->shipping_total, 'currency' => $order->currency])
                : __('mail.order.confirmation.shipping', ['amount' => $order->shipping_total, 'currency' => $order->currency]));
        }

        // What a discount code took off (the shipping charge above is shown before it).
        if ((float) $order->discount_total > 0) {
            $message->line($order->coupon_code
                ? __('mail.order.confirmation.discount_code', ['code' => $order->coupon_code, 'amount' => $order->discount_total, 'currency' => $order->currency])
                : __('mail.order.confirmation.discount', ['amount' => $order->discount_total, 'currency' => $order->currency]));
        }

        foreach ((array) ($order->metadata['tax_lines'] ?? []) as $taxLine) {
            $message->line(__('mail.order.confirmation.tax', ['name' => $taxLine['name'], 'rate' => $taxLine['rate'], 'amount' => $taxLine['amount'], 'currency' => $order->currency]));
        }

        if (is_array($order->shipping_address)) {
            $message->line(__('mail.order.confirmation.shipping_to', ['address' => $this->describe($order->shipping_address)]));
        }

        // The links to the files are short-lived and made when the order page is opened, so the email
        // sends the customer there rather than carrying a link that would go stale.
        if ($order->downloadGrants()->exists()) {
            $message->line(__('mail.order.confirmation.downloads'));
        }

        // Keys are secrets, so they are never put in an email: they are on the order page, behind the same link.
        if ($order->items->contains(fn ($item) => $item->delivers_keys)) {
            $message->line(__('mail.order.confirmation.keys'));
        }

        if ($giftCardTotal > 0) {
            $message->line(__('mail.order.confirmation.order_total', ['amount' => $order->grand_total, 'currency' => $order->currency]));
            $message->line($order->giftCard
                ? __('mail.order.confirmation.gift_card_ending', ['last_four' => $order->giftCard->last_four, 'amount' => $order->gift_card_total, 'currency' => $order->currency])
                : __('mail.order.confirmation.gift_card', ['amount' => $order->gift_card_total, 'currency' => $order->currency]));
        }

        return $message
            ->line(match (true) {
                $free => __('mail.order.confirmation.total_free', ['amount' => $order->grand_total, 'currency' => $order->currency]),
                $coveredByGiftCard => __('mail.order.confirmation.charged_nothing', ['currency' => $order->currency]),
                $giftCardTotal > 0 => __('mail.order.confirmation.charged', ['amount' => $order->amountDue()->toDecimal(), 'currency' => $order->currency]),
                default => __('mail.order.confirmation.total_paid', ['amount' => $order->grand_total, 'currency' => $order->currency]),
            })
            // Signed, so it works for guests who cannot sign in to see the order.
            ->action(__('mail.order.view'), URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
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
