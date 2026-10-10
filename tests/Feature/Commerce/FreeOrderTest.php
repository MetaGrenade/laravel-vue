<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\DownloadGrant;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ShippingZone;
use App\Notifications\OrderConfirmation;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutStarter;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundException;
use App\Support\Commerce\RefundRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * An order that costs nothing (a free product, or a discount code that covers all of it) is paid on
 * the spot: no payment is taken, so no provider is asked, or even needed.
 */
class FreeOrderTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpCommerce();
    }

    private function submit(array $overrides = []): TestResponse
    {
        return $this->post(route('shop.checkout.store'), $this->checkoutPayload($overrides));
    }

    private function freeCart(bool $digital = false): Product
    {
        $factory = Product::factory()->priced('0.00')->stocked(9);
        $product = ($digital ? $factory->digital() : $factory)->create(['name' => 'Free thing']);
        $this->cartWith($product);

        return $product;
    }

    // --- Free products -----------------------------------------------------------------------------

    #[Test]
    public function a_free_product_is_paid_on_the_spot_without_a_provider(): void
    {
        Notification::fake();
        $this->freeCart();

        $response = $this->submit();

        $order = Order::sole();
        $response->assertRedirect(app(CheckoutStarter::class)->completeUrl($order));

        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertNull($order->payment_provider);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->expires_at, 'nothing to wait for');
        $this->assertSame([], $this->stripe->created, 'Stripe was never asked');
        $this->assertSame(0, $order->payments()->count());
    }

    #[Test]
    public function a_free_order_is_recorded_as_such_in_its_history(): void
    {
        $this->freeCart();

        $this->submit();

        $this->assertSame([OrderEvent::PAID], Order::sole()->events()->pluck('type')->all());
        $this->assertSame('Nothing to pay: the order total is zero', Order::sole()->events()->sole()->message);
    }

    #[Test]
    public function a_free_order_takes_its_stock_and_empties_the_cart(): void
    {
        $product = $this->freeCart();

        $this->submit();

        $this->assertSame(8, InventoryItem::query()->where('product_id', $product->id)->sole()->quantity);
        $this->assertSame(0, $this->cartItemCount());
    }

    #[Test]
    public function the_customer_is_told_nothing_was_charged(): void
    {
        Notification::fake();
        $this->freeCart();

        $this->submit();

        Notification::assertSentOnDemand(OrderConfirmation::class, function (OrderConfirmation $notification) {
            $lines = collect($notification->toMail(new AnonymousNotifiable)->introLines);

            return $lines->contains(fn (string $line) => str_contains($line, 'Nothing was charged'))
                && $lines->contains(fn (string $line) => str_starts_with($line, 'Total: 0.00 USD'))
                && ! $lines->contains(fn (string $line) => str_contains($line, 'received your payment'));
        });
    }

    #[Test]
    public function the_order_page_shows_a_free_order_as_paid(): void
    {
        $this->freeCart();
        $this->submit();
        $order = Order::sole();

        $this->get(app(CheckoutStarter::class)->completeUrl($order))->assertInertia(fn (Assert $page) => $page
            ->where('order.payment_status', 'paid')
            ->where('order.grand_total', '0.00'));
    }

    #[Test]
    public function a_free_digital_product_is_delivered_and_complete_at_once(): void
    {
        $product = $this->freeCart(digital: true);
        ProductFile::factory()->for($product)->withContents('free bytes')->create();

        $this->submit();

        $order = Order::sole();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(1, DownloadGrant::query()->where('order_id', $order->id)->count());
        $this->assertSame([OrderEvent::PAID, OrderEvent::FULFILLED], $order->events()->orderBy('id')->pluck('type')->all());
    }

    #[Test]
    public function a_free_order_works_even_when_no_payment_provider_is_set_up(): void
    {
        config(['cashier.secret' => null]);
        $this->freeCart();

        $this->submit()->assertRedirect();

        $this->assertSame(OrderPaymentStatus::Paid, Order::sole()->payment_status);
    }

    #[Test]
    public function the_cart_page_offers_checkout_for_free_items_without_a_provider(): void
    {
        config(['cashier.secret' => null]);
        $this->freeCart();

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('checkoutAvailable', true));
    }

    #[Test]
    public function something_that_has_to_be_paid_for_still_needs_a_provider_and_reserves_nothing(): void
    {
        config(['cashier.secret' => null]);
        $product = Product::factory()->priced('10.00')->stocked(9)->create();
        $this->cartWith($product);

        $this->submit()->assertRedirect(route('shop.cart'))->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(9, InventoryItem::query()->where('product_id', $product->id)->sole()->quantity);
    }

    #[Test]
    public function the_price_of_a_free_product_is_shown_as_free(): void
    {
        $product = Product::factory()->priced('0.00')->create(['name' => 'Sampler']);

        $this->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page
            ->where('product.can_buy', true)
            ->where('product.prices.0.amount', '0.00'));
    }

    // --- Free because of a code --------------------------------------------------------------------

    #[Test]
    public function a_code_that_covers_the_whole_order_makes_it_free(): void
    {
        $product = Product::factory()->priced('25.00')->stocked(5)->create();
        $this->cartWith($product, 2);
        Coupon::factory()->percent('100')->create(['code' => 'EVERYTHING']);
        $this->post(route('shop.cart.coupon.store'), ['code' => 'EVERYTHING'])->assertSessionHasNoErrors();

        $this->submit()->assertRedirect();

        $order = Order::sole();
        $this->assertSame('50.00', $order->subtotal);
        $this->assertSame('50.00', $order->discount_total);
        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('EVERYTHING', $order->coupon_code);
        $this->assertSame([], $this->stripe->created);
    }

    #[Test]
    public function a_free_order_still_uses_up_the_code(): void
    {
        $this->cartWith(Product::factory()->priced('25.00')->stocked(5)->create());
        $coupon = Coupon::factory()->percent('100')->limited(total: 1)->create(['code' => 'ONCE']);
        $this->post(route('shop.cart.coupon.store'), ['code' => 'ONCE']);
        $this->submit()->assertRedirect();

        $other = Cart::create(['session_id' => Str::random(40), 'currency' => 'USD', 'coupon_id' => $coupon->id]);
        $product = Product::factory()->priced('25.00')->stocked(5)->create();
        CartManager::addItem($other, $product, null, $product->prices()->first(), 1);

        $this->withCookie(config('session.cookie'), $other->session_id)
            ->post(route('shop.checkout.store'), $this->checkoutPayload(['email' => 'second@example.com']))
            ->assertSessionHas('error', "Your discount code ONCE can't be used. That code has been fully redeemed.");
    }

    #[Test]
    public function a_code_does_not_make_the_shipping_free_unless_it_covers_it(): void
    {
        ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
        $this->cartWith(Product::factory()->priced('25.00')->stocked(5)->create());
        Coupon::factory()->percent('100')->create(['code' => 'ITEMSONLY']);
        $this->post(route('shop.cart.coupon.store'), ['code' => 'ITEMSONLY']);

        $this->submit()->assertRedirect();

        $order = Order::sole();
        $this->assertSame('5.00', $order->grand_total, 'the shipping is still to pay');
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertCount(1, $this->stripe->created);
    }

    // --- Mixed ------------------------------------------------------------------------------------

    #[Test]
    public function a_free_item_beside_a_paid_one_is_not_sent_to_stripe_and_the_total_still_matches(): void
    {
        $free = Product::factory()->priced('0.00')->stocked(9)->create(['name' => 'Free gift']);
        $paid = Product::factory()->priced('20.00')->stocked(9)->create(['name' => 'Hoodie']);
        $cart = $this->cartWith($free);
        CartManager::addItem($cart, $paid, null, $paid->prices()->first(), 1);

        $this->submit()->assertRedirect();

        $lines = collect($this->stripe->lastCreatedParams()['line_items']);
        $names = $lines->map(fn (array $line) => $line['price_data']['product_data']['name'])->all();

        $this->assertSame(['Hoodie'], $names);
        $this->assertSame(2000, (int) $lines->sum(fn (array $line) => $line['quantity'] * $line['price_data']['unit_amount']));
        $this->assertSame(OrderStatus::Pending, Order::sole()->status, 'there is something to pay');
        $this->assertSame(2, Order::sole()->items()->count(), 'but the free item is still on the order');
    }

    // --- Pressing twice ----------------------------------------------------------------------------

    #[Test]
    public function pressing_the_button_twice_places_one_free_order(): void
    {
        $this->freeCart();
        $token = (string) Str::uuid();

        $first = $this->submit(['token' => $token]);
        $second = $this->submit(['token' => $token]);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($first->headers->get('X-Inertia-Location'), $second->headers->get('X-Inertia-Location'));
    }

    // --- Afterwards --------------------------------------------------------------------------------

    #[Test]
    public function there_is_nothing_to_refund_on_a_free_order(): void
    {
        $this->freeCart();
        $this->submit();
        $order = Order::sole();

        $refunder = $this->app->make(OrderRefunder::class);

        $this->assertTrue($refunder->refundable($order)->isZero());

        $this->expectException(RefundException::class);
        $refunder->request($order, Money::parse('1.00', 'USD'), new RefundRequest(token: (string) Str::uuid()));
    }

    #[Test]
    public function the_expiry_job_leaves_a_free_order_alone(): void
    {
        $this->freeCart();
        $this->submit();

        $this->travel(3)->days();
        $this->artisan('commerce:expire-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::Processing, Order::sole()->status);
    }

    #[Test]
    public function paying_nothing_for_an_order_that_has_something_to_pay_is_refused(): void
    {
        [$order] = $this->placeOrder();

        $this->assertFalse($this->app->make(OrderLifecycle::class)->markFree($order));
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    #[Test]
    public function a_free_order_cannot_be_made_free_twice(): void
    {
        $this->freeCart();
        $this->submit();
        $order = Order::sole();

        $this->assertFalse($this->app->make(OrderLifecycle::class)->markFree($order));
        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count());
    }
}
