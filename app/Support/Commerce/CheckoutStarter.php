<?php

namespace App\Support\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Data\CheckoutClosure;
use App\Payments\Data\CheckoutContext;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Starts a checkout for a cart: places the order, creates the checkout at the
 * store's payment provider and records the pending payment. Returns where to
 * send the customer.
 *
 * A cart has at most one payable checkout at a time. Starting again closes the
 * earlier checkout at the provider and waits for that to be confirmed before a
 * replacement is created, so a shopper can never pay both.
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
     * @throws InvalidCheckoutInput When an address or shipping method cannot be accepted.
     * @throws OrderAlreadyPaidException When the earlier checkout turns out to have been paid.
     */
    public function start(Cart $cart, CustomerDetails $customer, CheckoutInput $input, ?string $idempotencyKey = null): array
    {
        // Two requests for one cart (a double click, two tabs) must not both
        // close and create checkouts at once.
        $lock = Cache::lock('checkout:cart:'.$cart->id, 120);

        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            throw new CheckoutException('Your checkout is already being prepared. Please wait a moment and try again.');
        }

        try {
            return $this->begin($cart, $customer, $input, $idempotencyKey);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{order: Order, url: string}
     */
    private function begin(Cart $cart, CustomerDetails $customer, CheckoutInput $input, ?string $idempotencyKey): array
    {
        $provider = $this->payments->active();

        // Pressing the button twice must not create two orders.
        $existing = $this->placer->existing($idempotencyKey);

        if ($existing !== null) {
            return ['order' => $existing, 'url' => $this->resumeUrl($existing)];
        }

        $this->supersedeEarlierCheckouts($cart);

        $order = $this->placer->place($cart, $customer, $provider->key(), $input, $idempotencyKey, function (Pricing $pricing) use ($provider) {
            // Something to pay needs a provider to pay through. Refused before the order is written, so
            // nothing is reserved. (An order that costs nothing needs none.)
            if (! $pricing->grandTotal->isZero() && ! $provider->isConfigured()) {
                throw new CheckoutException('Checkout is not available right now. Please try again later.');
            }
        });
        // Should a concurrent duplicate ever slip past the lock, it lands on the order the other request placed.
        if ($order->payments()->exists()) {
            return ['order' => $order, 'url' => $this->resumeUrl($order)];
        }

        // An order that costs nothing (a free product, or a code that covers all of it) is paid on the
        // spot. No payment is taken, so no provider is asked, or even needed.
        if (Money::parse($order->grand_total, $order->currency)->isZero()) {
            $this->lifecycle->markFree($order);

            return ['order' => $order->refresh(), 'url' => $this->completeUrl($order)];
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
     * Retire the cart's earlier unpaid orders before a new one is placed.
     *
     * Each earlier checkout is closed at its provider and the answer is
     * confirmed first; only then is the order cancelled and its stock freed.
     * If the provider cannot confirm, nothing is replaced and the shopper is
     * asked to try again, because an earlier checkout that stays payable next to
     * its replacement could be paid twice.
     */
    private function supersedeEarlierCheckouts(Cart $cart): void
    {
        $earlier = Order::query()
            ->where('cart_id', $cart->id)
            ->where('status', OrderStatus::Pending->value)
            ->get();

        foreach ($earlier as $order) {
            // A payment held for review has money behind it: a person decides.
            if ($order->payments()->where('status', PaymentStatus::Review->value)->exists()) {
                throw new CheckoutException('Your earlier payment is being reviewed, so a new checkout cannot be started yet.');
            }

            foreach ($order->payments()->where('status', PaymentStatus::Pending->value)->get() as $payment) {
                try {
                    $closure = $this->payments->provider($payment->provider)->closeCheckout($payment);
                } catch (PaymentException $exception) {
                    report($exception);

                    throw new CheckoutException("We couldn't close your earlier checkout, so a new one was not started. Please try again in a moment.", previous: $exception);
                }

                if ($closure === CheckoutClosure::Paid) {
                    throw new OrderAlreadyPaidException($order->refresh());
                }

                if ($closure === CheckoutClosure::Unresolved) {
                    throw new CheckoutException('Your earlier payment is still being confirmed. Please wait for it to finish before starting again.');
                }
            }

            $this->lifecycle->cancel($order, 'replaced');
        }
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
