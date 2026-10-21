<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Tebex\TebexWebhookVerifier;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Tebex's webhooks without Tebex: deliveries shaped like the ones its documentation shows (`tests/Fixtures/tebex/payment.json`),
 * signed the way it signs them, from one of the addresses it sends from.
 *
 * Use it with {@see InteractsWithTebex}.
 */
trait InteractsWithTebexWebhooks
{
    /** The endpoint's secret, made up in {@see self::setUpTebexWebhooks()} so no literal looks like a key to the secrets scan. */
    protected string $tebexWebhookSecret = '';

    protected function setUpTebexWebhooks(): void
    {
        $this->tebexWebhookSecret = 'whsec-'.str_repeat('b', 24);

        config([
            'tebex.webhook.secret' => $this->tebexWebhookSecret,
            'tebex.webhook.check_ip' => true,
        ]);
    }

    /**
     * A delivery for an order's payment: complete, for what the order cost, for the packages the order holds, naming the
     * order and the nonce its basket was made with the way Tebex is believed to carry them (on each product).
     *
     * @param  array<string, mixed>  $subject  Changes to the payment (replacing whole keys).
     * @return array<string, mixed>
     */
    protected function tebexPaymentFor(Order $order, Payment $payment, array $subject = []): array
    {
        $paid = (float) $order->grand_total;
        $custom = ['order' => $order->public_id, 'nonce' => $payment->raw['nonce'] ?? null];
        $base = $this->tebexFixture('payment');
        $order->loadMissing('items.tebexPackage');

        $base['price'] = ['amount' => $paid, 'currency' => $order->currency];
        $base['price_paid'] = ['amount' => $paid, 'currency' => $order->currency];
        $base['products'] = $order->items->sortBy('id')->map(fn ($item) => [
            ...$base['products'][0],
            'id' => $item->tebexPackage?->tebex_id,
            'name' => $item->description,
            'quantity' => $item->quantity,
            'base_price' => ['amount' => (float) $item->unit_price, 'currency' => $order->currency],
            'paid_price' => ['amount' => (float) $item->subtotal, 'currency' => $order->currency],
            'custom' => $custom,
        ])->values()->all();

        return array_replace($base, $subject);
    }

    /**
     * A recurring payment for an order: active, with the order's payment as its first (and last) payment, shaped like
     * the documentation's example (`tests/Fixtures/tebex/recurring-payment.json`).
     *
     * @param  array<string, mixed>  $subject  Changes to the recurring payment (replacing whole keys).
     * @return array<string, mixed>
     */
    protected function tebexRecurringFor(Order $order, Payment $payment, string $reference = 'tbx-r-4a1b2c', array $subject = []): array
    {
        $first = $this->tebexPaymentFor($order, $payment, ['recurring_payment_reference' => $reference]);
        $base = $this->tebexFixture('recurring-payment');

        $base['reference'] = $reference;
        $base['initial_payment'] = $first;
        $base['last_payment'] = $first;

        return array_replace($base, $subject);
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    protected function tebexDelivery(string $type, array $subject, ?string $id = null, ?string $date = null): array
    {
        return ['id' => $id ?? (string) Str::uuid(), 'type' => $type, 'date' => $date ?? now()->toIso8601String(), 'subject' => $subject];
    }

    /**
     * Send a delivery the way Tebex does: the body as written, signed with the secret, from Tebex's address.
     *
     * @param  array<string, mixed>|string  $body  A delivery, or the raw body to send as it is.
     */
    protected function deliverToTebexEndpoint(array|string $body, ?string $ip = '18.209.80.3', ?string $signature = null): TestResponse
    {
        $raw = is_string($body) ? $body : (string) json_encode($body);

        return $this->call('POST', route('tebex.webhook'), [], [], [], array_filter([
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? TebexWebhookVerifier::sign($raw, $this->tebexWebhookSecret),
        ]), $raw);
    }
}
