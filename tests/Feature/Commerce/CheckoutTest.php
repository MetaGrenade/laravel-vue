<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Payments\Exceptions\PaymentException;
use App\Support\Commerce\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submit(array $data = []): TestResponse
    {
        return $this->post(route('shop.checkout.store'), [
            'email' => 'buyer@example.com',
            'name' => 'Ada Buyer',
            'token' => (string) Str::uuid(),
            ...$data,
        ]);
    }

    #[Test]
    public function the_checkout_page_summarises_the_cart(): void
    {
        $product = Product::factory()->priced('19.99')->create(['name' => 'Hoodie']);
        $this->cartWith($product, 2);

        $this->get(route('shop.checkout'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/Checkout')
                ->where('cart.subtotal', '39.98')
                ->where('isGuest', true)
                ->where('available', true)
                ->where('provider', 'Stripe')
                ->has('token'));
    }

    #[Test]
    public function an_empty_cart_goes_back_to_the_cart_page(): void
    {
        $this->get(route('shop.checkout'))->assertRedirect(route('shop.cart'));
        $this->submit()->assertRedirect(route('shop.cart'));

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function a_guest_must_give_an_email_address(): void
    {
        $this->cartWith(Product::factory()->priced('5.00')->create());

        $this->submit(['email' => ''])->assertSessionHasErrors('email');
        $this->submit(['email' => 'not-an-email'])->assertSessionHasErrors('email');

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function guest_checkout_can_be_switched_off(): void
    {
        config(['commerce.checkout.guest' => false]);
        $this->cartWith(Product::factory()->priced('5.00')->create());

        $this->get(route('shop.checkout'))->assertRedirect(route('login'));
        $this->submit()->assertRedirect(route('login'));

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function a_guest_checkout_places_an_order_and_sends_them_to_stripe(): void
    {
        $product = Product::factory()->priced('19.99')->stocked(10)->create(['name' => 'Hoodie']);
        $cart = $this->cartWith($product, 2);

        $response = $this->submit();

        $order = Order::sole();
        $payment = Payment::sole();

        $response->assertRedirect($payment->checkout_url);
        $this->assertStringStartsWith('https://checkout.stripe.test/', $payment->checkout_url);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame('stripe', $order->payment_provider);
        $this->assertSame('buyer@example.com', $order->customer_email);
        $this->assertNull($order->owner_type, 'a guest order has no owner');
        $this->assertNull($order->user_id);
        $this->assertSame($cart->id, $order->cart_id);
        $this->assertSame('39.98', $order->subtotal);
        $this->assertSame('39.98', $order->grand_total);
        $this->assertSame('USD', $order->currency);
        $this->assertMatchesRegularExpression('/^MF-\d{6}$/', $order->number);
        $this->assertNotNull($order->expires_at);

        $item = $order->items->sole();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('19.99', $item->unit_price);
        $this->assertSame('39.98', $item->subtotal);
        $this->assertSame('Hoodie', $item->description);

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame('39.98', $payment->amount);

        // Stock is held while the customer pays.
        $this->assertSame(8, InventoryItem::sole()->quantity);
        $this->assertSame(-2, InventoryMovement::sole()->delta);
    }

    #[Test]
    public function stripe_is_asked_for_exactly_what_the_order_costs(): void
    {
        $product = Product::factory()->priced('19.99')->create(['name' => 'Hoodie']);
        $variant = ProductVariant::factory()->for($product)->priced('5.50')->create(['name' => 'Small']);
        $cart = $this->cartWith($product, 2);
        CartManager::addItem($cart, $product, $variant, $variant->prices()->firstOrFail(), 3);

        $this->submit();

        $params = $this->stripe->lastCreatedParams();
        $order = Order::sole();

        $this->assertSame('payment', $params['mode']);
        $this->assertSame($order->public_id, $params['client_reference_id']);
        $this->assertSame('buyer@example.com', $params['customer_email']);
        $this->assertSame('commerce', $params['metadata']['source']);
        $this->assertSame($order->public_id, $params['metadata']['order_public_id']);
        $this->assertSame($order->number, $params['payment_intent_data']['metadata']['order_number']);
        $this->assertSame($order->expires_at->getTimestamp(), $params['expires_at']);

        $this->assertSame([
            [
                'quantity' => 2,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => 1999,
                    'product_data' => ['name' => 'Hoodie'],
                ],
            ],
            [
                'quantity' => 3,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => 550,
                    'product_data' => ['name' => 'Hoodie — Small'],
                ],
            ],
        ], $params['line_items']);
        $this->assertSame('56.48', Order::sole()->grand_total);

        // The return link is signed so a guest can open their own confirmation.
        $this->assertStringContainsString('/checkout/complete/'.$order->public_id, $params['success_url']);
        $this->assertStringContainsString('signature=', $params['success_url']);
        $this->assertSame(route('shop.cart'), $params['cancel_url']);
    }

    #[Test]
    public function a_signed_in_customer_owns_the_order(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com', 'nickname' => 'Member']);
        $product = Product::factory()->priced('10.00')->create();
        $this->cartWith($product, 1, $user);

        $this->actingAs($user)->submit(['email' => 'ignored@example.com'])->assertRedirect();

        $order = Order::sole();

        $this->assertSame($user->id, $order->user_id);
        $this->assertTrue($order->isOwnedBy($user));
        $this->assertSame('member@example.com', $order->customer_email, 'the account email wins over the form');
        $this->assertSame($user->getMorphClass(), Payment::sole()->owner_type);
    }

    #[Test]
    public function the_page_can_be_a_full_navigation_for_inertia_requests(): void
    {
        $this->cartWith(Product::factory()->priced('5.00')->create());

        $response = $this->withHeaders(['X-Inertia' => 'true'])->submit();

        $response->assertStatus(409);
        $this->assertSame(Payment::sole()->checkout_url, $response->headers->get('X-Inertia-Location'));
    }

    #[Test]
    public function the_price_comes_from_the_catalogue_not_from_the_cart(): void
    {
        $product = Product::factory()->priced('50.00')->create();
        $cart = $this->cartWith($product);

        // Tamper with what the cart remembers.
        CartItem::where('cart_id', $cart->id)->update(['unit_price' => '0.01', 'total' => '0.01']);

        $this->submit();

        $this->assertSame('50.00', Order::sole()->grand_total);
        $this->assertSame(5000, $this->stripe->lastCreatedParams()['line_items'][0]['price_data']['unit_amount']);
    }

    #[Test]
    public function a_price_change_after_adding_to_the_cart_is_honoured_at_checkout(): void
    {
        $product = Product::factory()->priced('50.00')->create();
        $this->cartWith($product);

        $product->prices()->update(['amount' => '60.00']);

        $this->submit();

        $this->assertSame('60.00', Order::sole()->grand_total);
    }

    #[Test]
    public function a_withdrawn_product_blocks_checkout(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(5)->create();
        $this->cartWith($product);
        $product->update(['is_active' => false]);

        $this->submit()->assertRedirect(route('shop.cart'))->assertSessionHas('error');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function a_product_that_lost_its_price_blocks_checkout(): void
    {
        $product = Product::factory()->priced('5.00')->create();
        $this->cartWith($product);
        $product->prices()->update(['is_active' => false]);

        $this->submit()->assertSessionHas('error');

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function not_enough_stock_blocks_checkout_and_changes_nothing(): void
    {
        $plenty = Product::factory()->priced('5.00')->stocked(10)->create();
        $scarce = Product::factory()->priced('7.00')->stocked(1)->create(['name' => 'Last One']);
        $cart = $this->cartWith($plenty, 2);
        CartManager::addItem($cart, $scarce, null, $scarce->prices()->firstOrFail(), 3);

        $response = $this->submit();

        $response->assertRedirect(route('shop.cart'));
        $this->assertStringContainsString('Last One', session('error'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame([10, 1], InventoryItem::orderBy('id')->pluck('quantity')->all(), 'a failed checkout must not keep any stock');
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame([], $this->stripe->created);
    }

    #[Test]
    public function the_last_unit_can_only_be_bought_once(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(1)->create();
        $this->cartWith($product);

        $this->submit()->assertRedirect();
        $this->assertSame(0, InventoryItem::sole()->quantity);

        // Another shopper's cart for the same, now sold-out, product.
        $other = User::factory()->create();
        $this->cartWith($product, 1, $other);

        $this->actingAs($other)->submit()->assertSessionHas('error');

        $this->assertSame(1, Order::count());
        $this->assertSame(0, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function backorderable_stock_can_go_negative(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(1, allowBackorder: true)->create();
        $this->cartWith($product, 3);

        $this->submit()->assertRedirect();

        $this->assertSame(-2, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function variant_stock_is_used_before_product_stock(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(100)->create();
        $variant = ProductVariant::factory()->for($product)->priced('5.00')->stocked(2)->create();
        $this->cartWith($variant, 2);

        $this->submit()->assertRedirect();

        $this->assertSame(100, InventoryItem::whereNull('product_variant_id')->sole()->quantity);
        $this->assertSame(0, InventoryItem::whereNotNull('product_variant_id')->sole()->quantity);
    }

    #[Test]
    public function untracked_products_are_always_available(): void
    {
        $product = Product::factory()->priced('5.00')->create();
        $this->cartWith($product, 20);

        $this->submit()->assertRedirect();

        $this->assertSame(1, Order::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    #[Test]
    public function submitting_the_same_form_twice_places_one_order(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(10)->create();
        $this->cartWith($product, 2);
        $token = (string) Str::uuid();

        $first = $this->submit(['token' => $token]);
        $second = $this->submit(['token' => $token]);

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertCount(1, $this->stripe->created);
        $this->assertSame(8, InventoryItem::sole()->quantity, 'stock is held once');
    }

    #[Test]
    public function starting_again_replaces_the_earlier_unpaid_order(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(10)->create();
        $this->cartWith($product, 2);

        $this->submit();
        $firstOrder = Order::sole();
        $firstSession = Payment::sole()->provider_reference;

        $this->submit();

        $this->assertSame(OrderStatus::Cancelled, $firstOrder->fresh()->status);
        $this->assertSame(PaymentStatus::Canceled, Payment::where('provider_reference', $firstSession)->sole()->status);
        $this->assertSame(OrderStatus::Pending, Order::where('id', '!=', $firstOrder->id)->sole()->status);
        $this->assertSame(8, InventoryItem::sole()->quantity, 'only the new order holds stock');
        $this->assertSame([$firstSession], $this->stripe->expired, 'the abandoned Stripe session is closed');
    }

    #[Test]
    public function a_provider_failure_gives_the_stock_back_and_tells_the_customer(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(10)->create();
        $this->cartWith($product, 2);
        $this->stripe->failCreateWith = new \RuntimeException('Stripe is down');

        $this->submit()->assertRedirect(route('shop.cart'))->assertSessionHas('error');

        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
        $this->assertSame(0, Payment::count());
        $this->assertSame(10, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function an_unconfigured_provider_stops_checkout_without_placing_an_order(): void
    {
        config(['cashier.secret' => null]);
        $this->cartWith(Product::factory()->priced('5.00')->create());

        $this->get(route('shop.checkout'))
            ->assertInertia(fn (Assert $page) => $page->where('available', false));

        $this->submit()->assertSessionHas('error');

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function a_payment_exception_from_the_provider_is_not_shown_to_the_customer(): void
    {
        $this->cartWith(Product::factory()->priced('5.00')->create());
        $this->stripe->failCreateWith = new PaymentException('secret internal detail sk_live_abc');

        $this->submit();

        $this->assertStringNotContainsString('sk_live', (string) session('error'));
    }
}
