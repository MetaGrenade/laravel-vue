<?php

namespace App\Payments\Tebex;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Payments\Data\CheckoutClosure;
use App\Payments\Data\CheckoutContext;
use App\Payments\Data\CheckoutSession;
use App\Payments\Exceptions\PaymentException;
use App\Support\Commerce\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Selling the shop's own products through Tebex's Checkout API. EXPERIMENTAL: see `tebex.checkout_api`, and
 * {@see TebexSelling} for how it differs from selling Tebex's packages.
 *
 * The shop prices the order; Tebex is the merchant of record. Each line of the order becomes a custom item of the basket
 * (its name, its price before tax and the quantity), and Tebex adds the tax and takes the payment. The basket carries the
 * order's public id and a random value (the nonce, kept on the payment), as a package basket does, and **each item also
 * carries the id of the order line it is for**. That is what the payment's webhook is verified by: Tebex warns that a
 * webhook holds what was actually bought, and an app that manages its own basket must check it is what it expected, so
 * {@see TebexPayments} accepts a payment only for exactly the lines, quantities and prices the order holds.
 *
 * Only what needs no shipping can be sold this way, and what is sold is single payments: the shop's products have no
 * recurring form, and Tebex allows one subscription per basket anyway.
 */
class TebexCheckoutApi
{
    /** What a payment made this way says about itself (`payments.raw.mode`), so what is done to it later is too. */
    public const MODE = 'checkout_api';

    public function __construct(private readonly TebexCheckoutClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Make a basket of the order's lines and say where the buyer goes to pay.
     *
     * @throws PaymentException When the order holds something that cannot be sold this way, or Tebex cannot be asked or
     *                          does not say where to pay.
     */
    public function start(Order $order, CheckoutContext $context): CheckoutSession
    {
        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            throw new PaymentException("Order {$order->number} has nothing in it to sell.");
        }

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                throw new PaymentException("Order {$order->number} has a line that is not one of the shop's products, which the Checkout API cannot sell.");
            }

            if ($item->requires_shipping) {
                throw new PaymentException("Order {$order->number} has a line that has to be shipped, which cannot be paid for through Tebex.");
            }
        }

        $nonce = Str::random(32);
        $custom = ['order' => $order->public_id, 'nonce' => $nonce];

        [$first, $last] = $this->nameOf($order);

        $basket = $this->client->checkout(
            array_filter([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $order->customer_email,
                'return_url' => $context->cancelUrl,
                'complete_url' => $context->successUrl,
                'custom' => $custom,
            ], fn ($value) => $value !== null && $value !== ''),
            $this->items($order, $custom),
            strtoupper($order->currency),
        );

        $hostedUrl = $this->payUrl($basket);

        $embedded = TebexCheckoutMode::current()->isEmbedded();

        return new CheckoutSession(
            provider: 'tebex',
            reference: $basket->ident,
            // Tebex's own page, or (when the checkout is embedded) the shop's link, which shows Tebex.js on the shop's page and
            // is also where the buyer comes back to if they leave.
            redirectUrl: $embedded ? URL::signedRoute('shop.tebex.continue', ['order' => $order->public_id]) : $hostedUrl,
            expiresAt: $context->expiresAt,
            raw: [
                'mode' => self::MODE,
                'nonce' => $nonce,
                'hosted_url' => $hostedUrl,
                ...$this->quote($order, $basket),
            ],
        );
    }

    /**
     * Where this buyer goes next, on the shop's own page: on to pay, or, if they already have, to the order.
     *
     * @throws PaymentException When Tebex cannot be asked ({@see TebexException}) or does not say where to pay.
     */
    public function resume(Order $order, Payment $payment): SignInStep
    {
        $basket = $this->client->basket((string) $payment->provider_reference, strtoupper($order->currency));

        // Paid already (a second visit after paying): nothing more to do here, and nothing to change.
        if ($basket->isPaid()) {
            return SignInStep::redirect(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
        }

        $hostedUrl = $this->payUrl($basket);

        return TebexCheckoutMode::current()->isEmbedded()
            ? SignInStep::pay($basket->ident, $hostedUrl)
            : SignInStep::redirect($hostedUrl);
    }

    /**
     * Make an unpaid basket unpayable, and say whether it is: the rows are taken out of it, since a basket with nothing in it
     * has nothing to pay for. One that is already paid is left for the webhook to settle.
     *
     * @throws PaymentException When Tebex cannot be asked.
     */
    public function close(Payment $payment, string $currency): CheckoutClosure
    {
        $basket = $this->client->basket((string) $payment->provider_reference, $currency);

        if ($basket->isPaid()) {
            return CheckoutClosure::Unresolved;
        }

        foreach ($basket->rows as $row) {
            $this->client->removeRow($basket->ident, $row['id']);
        }

        // Asked again rather than trusted: what counts is that Tebex says it is empty and unpaid.
        $basket = $this->client->basket((string) $payment->provider_reference, $currency);

        return ! $basket->isPaid() && $basket->rows === [] ? CheckoutClosure::Closed : CheckoutClosure::Unresolved;
    }

    /**
     * The order's lines as the basket's items, in the order they were placed in: each a custom package at the line's price
     * before tax, carrying the order and nonce and the line it is for.
     *
     * @param  array<string, string>  $custom
     * @return list<array<string, mixed>>
     */
    private function items(Order $order, array $custom): array
    {
        return $order->items->sortBy('id')->values()->map(fn (OrderItem $item) => [
            'package' => [
                'name' => Str::limit($item->description, 250, ''),
                'price' => (float) $item->unit_price,
                'type' => 'single',
                'custom' => [...$custom, 'line' => $item->id],
            ],
            'qty' => $item->quantity,
        ])->all();
    }

    /**
     * Where the buyer pays: the address Tebex gave, or, if it gave none, the one the Checkout API documents.
     *
     * @throws PaymentException When the address is not a secure one.
     */
    private function payUrl(TebexCheckoutBasket $basket): string
    {
        $url = $basket->checkoutUrl ?? rtrim((string) config('tebex.checkout_api.pay_url', 'https://pay.tebex.io'), '/').'/'.rawurlencode($basket->ident);

        // The buyer is sent to it or offered it as a link, so only a secure web address will do.
        if (preg_match('#^https://#i', $url) !== 1) {
            throw new PaymentException('Tebex did not say where to pay.');
        }

        return $url;
    }

    /**
     * What Tebex quoted for the basket, and whether it differs from what the shop priced (it adds the tax itself, so the
     * price before tax is what is compared).
     *
     * @return array{quoted: array<string, string>, price_differs: bool}
     */
    private function quote(Order $order, TebexCheckoutBasket $basket): array
    {
        $expected = Money::parse($order->subtotal, $order->currency);
        $differs = ! $basket->subTotal->equals($expected);

        if ($differs) {
            // Whoever reconciles the payment should know; the buyer pays what Tebex charges.
            Log::warning('Tebex quoted a different price before tax than the shop did for an order.', [
                'order' => $order->number,
                'shop' => $expected->toDecimal(),
                'tebex' => $basket->subTotal->toDecimal(),
            ]);
        }

        return [
            'quoted' => [
                'currency' => strtoupper($order->currency),
                'base_price' => $basket->subTotal->toDecimal(),
                'sales_tax' => $basket->tax->toDecimal(),
                'total_price' => $basket->total->toDecimal(),
            ],
            'price_differs' => $differs,
        ];
    }

    /**
     * The buyer's name as Tebex takes it: the first word, and the rest.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function nameOf(Order $order): array
    {
        $name = trim((string) $order->customer_name);

        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        return [$parts[0], $parts[1] ?? null];
    }
}
