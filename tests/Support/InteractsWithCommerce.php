<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Payments\Stripe\StripeGateway;
use App\Support\Commerce\CartManager;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared set-up for commerce tests: a configured Stripe provider backed by a
 * fake gateway, a way to deliver signed webhooks, and cart helpers.
 */
trait InteractsWithCommerce
{
    protected FakeStripeGateway $stripe;

    protected string $webhookSecret = 'whsec_commerce_test';

    /**
     * The browser session guest carts belong to. The test client sends no cookies by
     * default, which would give every request a new session and an empty cart.
     */
    protected string $guestSessionId = '';

    protected function setUpCommerce(): void
    {
        config([
            'commerce.currency' => 'USD',
            'commerce.provider' => 'stripe',
            'commerce.checkout.guest' => true,
            'cashier.secret' => 'sk_test_fake',
            'cashier.webhook.secret' => $this->webhookSecret,
            'cashier.webhook.cli_secret' => null,
        ]);

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);

        $this->guestSessionId = Str::random(40);
        $this->withCookie(config('session.cookie'), $this->guestSessionId);
    }

    /**
     * A cart holding the given product, owned by the user or by the test session.
     */
    protected function cartWith(Product|ProductVariant $purchasable, int $quantity = 1, ?User $user = null): Cart
    {
        $variant = $purchasable instanceof ProductVariant ? $purchasable : null;
        $product = $variant ? $variant->product : $purchasable;
        $price = $variant?->prices()->first() ?? $product->prices()->firstOrFail();

        $cart = Cart::create([
            'user_id' => $user?->id,
            'session_id' => $user ? null : $this->guestSessionId,
            'currency' => 'USD',
        ]);

        CartManager::addItem($cart, $product, $variant, $price, $quantity);

        return $cart->fresh(['items']);
    }

    /**
     * Put a product in the cart of the current browser session through the real endpoint.
     */
    protected function addToCart(Product|ProductVariant $purchasable, int $quantity = 1): TestResponse
    {
        $variant = $purchasable instanceof ProductVariant ? $purchasable : null;
        $product = $variant ? $variant->product : $purchasable;

        return $this->post(route('shop.cart.items.store'), array_filter([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
        ]));
    }

    /**
     * Run a real checkout as the current guest session and return what it created.
     *
     * @return array{0: Order, 1: Payment, 2: Product}
     */
    protected function placeOrder(string $price = '25.00', int $quantity = 1, int $stock = 5): array
    {
        $product = Product::factory()->priced($price)->stocked($stock)->create();
        $this->cartWith($product, $quantity);

        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        return [$order, $order->payments()->firstOrFail(), $product];
    }

    /**
     * A valid address as the checkout form posts it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function addressFields(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Ada Buyer',
            'line1' => '1 Test Street',
            'city' => 'London',
            'postal_code' => 'N1 1AA',
            'country' => 'GB',
        ], $overrides);
    }

    /**
     * A complete checkout form for a cart of physical goods (a shipping address included).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function checkoutPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'email' => 'buyer@example.com',
            'name' => 'Ada Buyer',
            'token' => (string) Str::uuid(),
            'shipping_address' => $this->addressFields(),
        ], $overrides);
    }

    /**
     * The Checkout Session Stripe would report for a payment, optionally already paid.
     *
     * @return array<string, mixed>
     */
    protected function sessionFor(Payment $payment, bool $paid = true): array
    {
        return $paid
            ? $this->stripe->pay($payment->provider_reference)
            : $this->stripe->sessions[$payment->provider_reference];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function stripeEvent(string $type, array $session, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'evt_'.Str::lower(Str::random(20)),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $session],
        ], $overrides);
    }

    /**
     * Deliver a webhook the way Stripe does: raw JSON body plus a signature header.
     *
     * @param  array<string, mixed>  $event
     */
    protected function deliverStripeEvent(array $event, ?string $secret = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $this->webhookSecret);

        return $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
            $body,
        );
    }

    protected function cartItemCount(): int
    {
        return CartItem::query()->count();
    }
}
