<?php

namespace App\Support\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Data\CheckoutContext;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentManager;
use Illuminate\Support\Facades\URL;

/**
 * Starts a checkout for a cart: places the order, creates the checkout at the
 * store's payment provider and records the pending payment. Returns where to
 * send the customer.
 */
class CheckoutStarter
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly OrderPlacer $placer,
        private readonly OrderLifecycle $lifecycle,
    ) {}

    public function isAvailable(): bool
    {
        return $this->payments->active()->isConfigured();
    }

    /**
     * @return array{order: Order, url: string}
     *
     * @throws CheckoutException
     */
    public function start(Cart $cart, CustomerDetails $customer, ?string $idempotencyKey = null): array
    {
        $provider = $this->payments->active();

        if (! $provider->isConfigured()) {
            throw new CheckoutException('Checkout is not available right now. Please try again later.');
        }

        // Pressing the button twice must not create two orders.
        $existing = $this->placer->existing($idempotencyKey);

        if ($existing !== null) {
            return ['order' => $existing, 'url' => $this->resumeUrl($existing)];
        }

        $order = $this->placer->place($cart, $customer, $provider->key(), $idempotencyKey);

        // A concurrent duplicate submission returns the order the other request placed.
        if ($order->payments()->exists()) {
            return ['order' => $order, 'url' => $this->resumeUrl($order)];
        }

        try {
            $session = $provider->startCheckout($order, new CheckoutContext(
                successUrl: $this->completeUrl($order),
                cancelUrl: route('shop.cart'),
                expiresAt: $order->expires_at ?? now()->addMinutes(60),
                locale: null,
            ));
        } catch (PaymentException $exception) {
            // The customer was never sent anywhere, so give the stock back.
            $this->lifecycle->cancel($order, 'provider_error');
            report($exception);

            throw new CheckoutException('We could not start the payment. Please try again in a moment.', previous: $exception);
        }

        // Providers return the same session for a repeated request, so a racing
        // duplicate lands on the payment the first request recorded.
        $payment = Payment::query()->firstOrNew(
            ['provider' => $session->provider, 'provider_reference' => $session->reference],
            [
                'order_id' => $order->id,
                'status' => PaymentStatus::Pending,
                'amount' => $order->grand_total,
                'currency' => $order->currency,
                'checkout_url' => $session->redirectUrl,
                'expires_at' => $session->expiresAt,
                'raw' => $session->raw ?: null,
            ],
        );

        if (! $payment->exists) {
            $payment->owner_type = $order->owner_type;
            $payment->owner_id = $order->owner_id;
            $payment->save();
        }

        return ['order' => $order, 'url' => $session->redirectUrl];
    }

    /**
     * The link the customer returns to after paying. Signed so a guest, who has
     * no account to authorise against, can open their own confirmation.
     */
    public function completeUrl(Order $order): string
    {
        return URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]);
    }

    /**
     * Where to send someone who submits an order they already started.
     */
    private function resumeUrl(Order $order): string
    {
        $payment = $order->payments()->latest('id')->first();

        if ($order->status === OrderStatus::Pending && $payment?->checkout_url) {
            return $payment->checkout_url;
        }

        return $this->completeUrl($order);
    }
}
