<?php

namespace App\Payments\Providers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Capability;
use App\Payments\Contracts\PaymentProvider;
use App\Payments\Data\CheckoutClosure;
use App\Payments\Data\CheckoutContext;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\RefundOutcome;
use App\Payments\Data\WebhookOutcome;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Tebex\TebexClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sells Tebex packages: the order's lines are put in a Tebex basket, and the customer pays on Tebex's hosted checkout.
 *
 * Tebex is the merchant of record. It works out tax, applies its own coupons, creator codes and gift cards, and takes
 * the payment, so what the buyer pays can differ from what the shop quoted; the order carries the shop's quote (the
 * packages at their price before tax), and the payment Tebex's own figures once it reports them.
 *
 * **Settling an order is not built yet.** Tebex tells the shop a payment happened by webhook, and the endpoint that
 * receives it (and the refunds, disputes and recurring payments that follow) is the next slice. Until then an order
 * placed through this provider stays pending and expires, so the provider is registered only when
 * `TEBEX_CHECKOUT_PREVIEW` is set (see docs/tebex.md), for trying a basket against a real store, never for taking money.
 */
class TebexProvider implements PaymentProvider
{
    public const KEY = 'tebex';

    public function __construct(private readonly TebexClient $client) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Tebex';
    }

    public function capabilities(): array
    {
        return [
            Capability::ONE_TIME_PAYMENTS,
            Capability::HOSTED_CHECKOUT,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Make a basket for the order, put its packages in it and say where to pay.
     *
     * The basket carries the order's public id and a random value (the nonce, kept on the payment), which is what the
     * payment's webhook will be matched to the order by.
     */
    public function startCheckout(Order $order, CheckoutContext $context): CheckoutSession
    {
        $order->loadMissing('items.tebexPackage');

        // In the order they were placed in (a relation has none of its own), so a basket is always built the same way.
        $items = $order->items->sortBy('id')->values();

        if ($items->isEmpty()) {
            throw new PaymentException("Order {$order->number} has nothing in it to sell.");
        }

        foreach ($items as $item) {
            if (blank($item->tebexPackage?->ident)) {
                throw new PaymentException("Order {$order->number} has a line that is not a Tebex package the shop can put in a basket.");
            }
        }

        $nonce = Str::random(32);

        $basket = $this->client->createBasket(
            $context->successUrl,
            $context->cancelUrl,
            ['order' => $order->public_id, 'nonce' => $nonce],
            $context->ipAddress,
        );

        foreach ($items as $item) {
            $basket = $this->client->addPackage($basket->ident, (string) $item->tebexPackage?->ident, $item->quantity);
        }

        if ($basket->checkoutUrl === null) {
            throw new PaymentException("Tebex did not say where to pay for order {$order->number}.");
        }

        if ($basket->currency !== strtoupper($order->currency)) {
            throw new PaymentException("Tebex priced order {$order->number} in {$basket->currency}, not {$order->currency}.");
        }

        $differs = $basket->basePrice->toDecimal() !== number_format((float) $order->subtotal, 2, '.', '');

        if ($differs) {
            // The shop priced the order from its copy of the catalogue, and Tebex's price has moved since. The
            // customer pays what Tebex charges; this is for whoever reconciles the payment.
            Log::warning('Tebex quoted a different price than the shop did for an order.', [
                'order' => $order->number,
                'shop' => $order->subtotal,
                'tebex' => $basket->basePrice->toDecimal(),
            ]);
        }

        return new CheckoutSession(
            provider: self::KEY,
            reference: $basket->ident,
            redirectUrl: $basket->checkoutUrl,
            expiresAt: $context->expiresAt,
            raw: [
                'nonce' => $nonce,
                'quoted' => [
                    'currency' => $basket->currency,
                    'base_price' => $basket->basePrice->toDecimal(),
                    'sales_tax' => $basket->salesTax->toDecimal(),
                    'total_price' => $basket->totalPrice->toDecimal(),
                ],
                'price_differs' => $differs,
            ],
        );
    }

    /**
     * Make an unpaid basket unpayable, and say whether it is.
     *
     * Tebex has no call that cancels a basket, so the packages are taken out of it: a basket with nothing in it has
     * nothing to pay for. A basket that is already paid cannot be closed, and is left for the webhook to settle.
     */
    public function closeCheckout(Payment $payment): CheckoutClosure
    {
        $basket = $this->client->basket($payment->provider_reference);

        if ($basket->isPaid()) {
            return CheckoutClosure::Unresolved;
        }

        foreach ($basket->packages as $package) {
            $basket = $this->client->removePackage($basket->ident, (string) $package['id']);
        }

        // Asked again rather than trusted: what counts is that Tebex says it is empty and unpaid.
        $basket = $this->client->basket($payment->provider_reference);

        return ! $basket->isPaid() && $basket->packages === []
            ? CheckoutClosure::Closed
            : CheckoutClosure::Unresolved;
    }

    /**
     * Nothing to ask: Tebex does not report a payment's outcome when asked, only by webhook, and an order is never
     * settled from anything else.
     */
    public function reconcile(Payment $payment): void
    {
        // Intentionally empty. See the class comment.
    }

    public function handleWebhook(Request $request): WebhookOutcome
    {
        throw new PaymentException('The shop does not handle Tebex webhooks yet.');
    }

    public function refund(Refund $refund): RefundOutcome
    {
        throw new PaymentException('The shop cannot refund through Tebex yet: only providers that say they can are asked to.');
    }

    public function syncRefunds(Payment $payment): void
    {
        // Intentionally empty: refunds made in the Tebex panel are not read back yet.
    }

    public function paymentUrl(Payment $payment): ?string
    {
        return null;
    }
}
