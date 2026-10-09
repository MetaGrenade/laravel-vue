<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Notifications\OrderConfirmation;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\Discounts\CouponRedemptions;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundException;
use App\Support\Commerce\RefundRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * An order placed with a discount code: what it records, what the customer is asked to pay, and how
 * the code's uses are kept honest when several people want the same one.
 */
class CouponCheckoutTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    private function ukShopWithVat(): void
    {
        ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20']);
    }

    /**
     * Fill the current browser's cart (as a guest).
     */
    private function fill(string $price = '50.00', int $quantity = 2, bool $digital = false): Cart
    {
        $factory = Product::factory()->priced($price)->stocked(50);
        $product = ($digital ? $factory->digital() : $factory)->create(['name' => 'Hoodie']);

        return $this->cartWith($product, $quantity);
    }

    private function apply(string $code): TestResponse
    {
        return $this->post(route('shop.cart.coupon.store'), ['code' => $code]);
    }

    /**
     * Another shopper in another browser, with a cart holding a 50.00 item and the code on it.
     * (Laravel only accepts a 40 character session id; anything else starts a new, empty session.)
     */
    private function anotherShopperWith(Coupon $coupon): string
    {
        $session = Str::random(40);
        $cart = Cart::create(['session_id' => $session, 'currency' => 'USD', 'coupon_id' => $coupon->id]);
        $product = Product::factory()->priced('50.00')->stocked(50)->create();
        CartManager::addItem($cart, $product, null, $product->prices()->first(), 1);

        return $session;
    }

    private function checkoutAs(string $session, string $email): TestResponse
    {
        return $this->withCookie(config('session.cookie'), $session)
            ->post(route('shop.checkout.store'), $this->checkoutPayload(['email' => $email, 'shipping_address' => $this->addressFields()]));
    }

    private function submit(array $overrides = []): TestResponse
    {
        return $this->post(route('shop.checkout.store'), $this->checkoutPayload($overrides));
    }

    /**
     * What Stripe was asked to charge: every line's quantity times its unit price, in minor units.
     */
    private function stripeTotal(): int
    {
        return (int) collect($this->stripe->lastCreatedParams()['line_items'])
            ->sum(fn (array $line) => $line['quantity'] * $line['price_data']['unit_amount']);
    }

    // --- What the order records --------------------------------------------------------------------

    #[Test]
    public function the_order_records_the_code_and_what_it_took_off(): void
    {
        $this->ukShopWithVat();
        $this->fill('50.00', 2);
        $coupon = Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');

        $this->submit()->assertRedirect();

        $order = Order::sole();

        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertSame('100.00', $order->subtotal);
        $this->assertSame('10.00', $order->discount_total);
        $this->assertSame('5.00', $order->shipping_total);
        $this->assertSame('19.00', $order->tax_total, '20% of the 90.00 left on the items and of the 5.00 shipping');
        $this->assertSame('114.00', $order->grand_total);
        $this->assertSame('10.00', $order->items->sole()->discount_total, 'the discount sits on its line');
        $this->assertEquals(
            ['coupon_id' => $coupon->id, 'code' => 'SAVE10', 'type' => 'percent', 'items' => '10.00', 'shipping' => '0.00'],
            $order->metadata['discount'],
        );
        $this->assertSame([['name' => 'VAT', 'rate' => '20', 'amount' => '19.00']], $order->metadata['tax_lines']);
    }

    #[Test]
    public function an_order_without_a_code_records_none(): void
    {
        $this->fill();

        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect();

        $order = Order::sole();

        $this->assertNull($order->coupon_id);
        $this->assertNull($order->coupon_code);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertArrayNotHasKey('discount', $order->metadata ?? []);
    }

    #[Test]
    public function free_shipping_is_recorded_as_a_discount_on_the_shipping(): void
    {
        $this->ukShopWithVat();
        $this->fill('50.00', 2);
        Coupon::factory()->freeShipping()->create(['code' => 'SHIPFREE']);
        $this->apply('SHIPFREE');

        $this->submit()->assertRedirect();

        $order = Order::sole();

        $this->assertSame('5.00', $order->shipping_total);
        $this->assertSame('5.00', $order->discount_total);
        $this->assertSame('0.00', $order->items->sole()->discount_total);
        $this->assertSame('5.00', $order->metadata['discount']['shipping']);
        $this->assertSame('20.00', $order->tax_total, 'no tax on shipping that is not charged');
        $this->assertSame('120.00', $order->grand_total);
    }

    #[Test]
    public function editing_the_coupon_later_does_not_change_the_order(): void
    {
        $this->fill('50.00', 2);
        $coupon = Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');
        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect();

        $coupon->update(['code' => 'RENAMED', 'value' => '90']);

        $order = Order::sole()->fresh();

        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertSame('10.00', $order->discount_total);
        $this->assertSame('SAVE10', $order->metadata['discount']['code']);
    }

    // --- What the customer is asked to pay ---------------------------------------------------------

    #[Test]
    public function stripe_is_asked_for_exactly_the_discounted_total(): void
    {
        $this->ukShopWithVat();
        $this->fill('50.00', 2);
        Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');

        $this->submit()->assertRedirect();

        $lines = collect($this->stripe->lastCreatedParams()['line_items']);

        $this->assertSame(11400, $this->stripeTotal());
        // The item is charged at what is left after the discount: 2 at 45.00.
        $this->assertSame(['quantity' => 2, 'unit_amount' => 4500], [
            'quantity' => $lines->first()['quantity'],
            'unit_amount' => $lines->first()['price_data']['unit_amount'],
        ]);
        $this->assertSame('114.00', $order = Order::sole()->grand_total);
        $this->assertSame($order, Order::sole()->payments()->sole()->amount);
    }

    #[Test]
    public function a_price_that_does_not_divide_between_the_units_is_split_to_the_cent(): void
    {
        // 3 at 10.00 with 1.00 off is 29.00: one at 9.66 and two at 9.67.
        $this->fill('10.00', 3);
        Coupon::factory()->fixed('1.00')->create(['code' => 'ONEOFF']);
        $this->apply('ONEOFF');

        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect();

        $lines = collect($this->stripe->lastCreatedParams()['line_items']);

        $this->assertSame(2900, $this->stripeTotal());
        $this->assertSame(3, $lines->sum('quantity'), 'the quantity ordered is kept');
        $this->assertEqualsCanonicalizing(
            [[1, 966], [2, 967]],
            $lines->map(fn (array $line) => [$line['quantity'], $line['price_data']['unit_amount']])->all(),
        );
        $this->assertSame('29.00', Order::sole()->grand_total);
    }

    #[Test]
    public function lines_always_add_up_to_the_order_total(): void
    {
        // Several lines and an awkward percentage, with shipping and tax on top.
        $this->ukShopWithVat();
        $first = Product::factory()->priced('33.33')->stocked(9)->create();
        $second = Product::factory()->priced('12.34')->stocked(9)->create();
        $cart = $this->cartWith($first, 3);
        CartManager::addItem($cart, $second, null, $second->prices()->first(), 7);
        Coupon::factory()->percent('17.5')->create(['code' => 'ODD']);
        $this->apply('ODD');

        $this->submit()->assertRedirect();

        $order = Order::sole();

        $this->assertSame(Money::parse($order->grand_total, 'USD')->minor, $this->stripeTotal());
    }

    #[Test]
    public function free_shipping_sends_no_shipping_line_to_stripe(): void
    {
        $this->ukShopWithVat();
        $this->fill('50.00', 2);
        Coupon::factory()->freeShipping()->create(['code' => 'SHIPFREE']);
        $this->apply('SHIPFREE');

        $this->submit()->assertRedirect();

        $names = collect($this->stripe->lastCreatedParams()['line_items'])->map(fn (array $line) => $line['price_data']['product_data']['name']);

        $this->assertFalse($names->contains(fn (string $name) => str_starts_with($name, 'Shipping')));
        $this->assertSame(12000, $this->stripeTotal());
    }

    #[Test]
    public function a_line_that_is_entirely_discounted_is_sent_at_nothing(): void
    {
        $this->ukShopWithVat();
        $free = Product::factory()->priced('10.00')->stocked(9)->create(['name' => 'Gift']);
        $paid = Product::factory()->priced('40.00')->stocked(9)->create(['name' => 'Hoodie']);
        $cart = $this->cartWith($free, 1);
        CartManager::addItem($cart, $paid, null, $paid->prices()->first(), 1);
        Coupon::factory()->percent('100')->forProducts([$free])->create(['code' => 'FREEGIFT']);
        $this->apply('FREEGIFT');

        $this->submit()->assertRedirect();

        $this->assertSame(Money::parse(Order::sole()->grand_total, 'USD')->minor, $this->stripeTotal());
    }

    // --- Keeping the uses honest -------------------------------------------------------------------

    #[Test]
    public function a_code_used_up_after_it_was_applied_is_refused_at_checkout(): void
    {
        $this->fill();
        $coupon = Coupon::factory()->limited(total: 1)->create(['code' => 'ONCE']);
        $this->apply('ONCE')->assertSessionHasNoErrors();

        // Someone else got there first.
        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'processing']);

        $this->submit(['shipping_address' => $this->addressFields()])
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', "Your discount code ONCE can't be used. That code has been fully redeemed.");

        $this->assertSame(1, Order::query()->count(), 'no order was placed for this cart');
        $this->assertSame(0, $this->stripe->created === [] ? 0 : count($this->stripe->created));
    }

    #[Test]
    public function an_expired_code_is_refused_at_checkout_and_nothing_is_charged(): void
    {
        $this->fill();
        $coupon = Coupon::factory()->create(['code' => 'SOON']);
        $this->apply('SOON');
        $coupon->update(['ends_at' => now()->subMinute()]);

        $this->submit(['shipping_address' => $this->addressFields()])
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame([], $this->stripe->created);
    }

    #[Test]
    public function the_last_use_of_a_code_goes_to_whoever_checks_out_first(): void
    {
        $coupon = Coupon::factory()->limited(total: 1)->create(['code' => 'LAST']);
        $first = $this->fill();
        $second = $this->anotherShopperWith($coupon);

        // Both have the code on their cart: neither has used it yet.
        $first->update(['coupon_id' => $coupon->id]);

        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect();
        $this->assertSame(1, Order::query()->where('coupon_id', $coupon->id)->count());

        $this->checkoutAs($second, 'second@example.com')
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', "Your discount code LAST can't be used. That code has been fully redeemed.");

        $this->assertSame(1, Order::query()->where('coupon_id', $coupon->id)->count(), 'the code was used once');
    }

    #[Test]
    public function a_cancelled_order_gives_the_use_to_the_next_person(): void
    {
        $coupon = Coupon::factory()->limited(total: 1)->create(['code' => 'LAST']);
        $this->fill();
        $this->apply('LAST');
        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect();

        app(OrderLifecycle::class)->cancel(Order::sole(), 'expired');

        $other = $this->anotherShopperWith($coupon);

        $this->checkoutAs($other, 'other@example.com')
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertSame(1, Order::query()->where('coupon_id', $coupon->id)->where('status', '!=', OrderStatus::Cancelled->value)->count());
    }

    #[Test]
    public function going_back_to_the_payment_page_with_the_same_cart_does_not_lose_the_code(): void
    {
        // The shopper reached the payment page, came back and pressed pay again. The earlier order
        // from this cart is replaced, so the single use is still theirs.
        $this->fill();
        $coupon = Coupon::factory()->limited(total: 1)->create(['code' => 'ONCE']);
        $this->apply('ONCE');

        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect();
        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect()->assertSessionMissing('error');

        $orders = Order::query()->where('coupon_id', $coupon->id)->get();

        $this->assertSame(1, $orders->where('status', '!=', OrderStatus::Cancelled)->count(), 'one live order holds the use');
        $this->assertSame(1, $orders->where('status', OrderStatus::Cancelled)->count(), 'the first was replaced');
    }

    #[Test]
    public function a_guest_is_held_to_the_per_customer_limit_when_they_give_their_email(): void
    {
        $coupon = Coupon::factory()->limited(perCustomer: 1)->create(['code' => 'ONEEACH']);

        $this->fill();
        $this->apply('ONEEACH')->assertSessionHasNoErrors();
        $this->submit(['email' => 'Ada@Example.com', 'shipping_address' => $this->addressFields()])->assertRedirect();
        $this->assertSame(1, Order::query()->where('coupon_id', $coupon->id)->count());

        // The same person, a new cart.
        $again = $this->anotherShopperWith($coupon);

        $this->checkoutAs($again, 'ada@example.com')
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', "Your discount code ONEEACH can't be used. You've already used that code.");

        $this->assertSame(1, Order::query()->where('coupon_id', $coupon->id)->count());
    }

    // --- After the order ---------------------------------------------------------------------------

    #[Test]
    public function the_confirmation_email_shows_the_discount(): void
    {
        Notification::fake();
        $this->ukShopWithVat();
        $this->fill('50.00', 2);
        Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');
        $this->submit()->assertRedirect();
        $payment = Order::sole()->payments()->sole();

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        Notification::assertSentOnDemand(OrderConfirmation::class, function (OrderConfirmation $notification) {
            $lines = collect($notification->toMail(new AnonymousNotifiable)->introLines);

            return $lines->contains(fn (string $line) => str_contains($line, 'Discount (SAVE10)') && str_contains($line, '10.00 USD'));
        });
    }

    #[Test]
    public function a_discounted_order_can_be_refunded_up_to_what_was_actually_paid(): void
    {
        Notification::fake();
        $this->fill('50.00', 2);
        Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');
        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect();
        $order = Order::sole();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();
        $order->refresh();

        $this->assertSame('90.00', $order->grand_total);

        $refunder = $this->app->make(OrderRefunder::class);
        $refund = fn (string $amount) => $refunder->request($order, Money::parse($amount, 'USD'), new RefundRequest(token: (string) Str::uuid()));

        $this->assertSame('succeeded', $refund('40.00')->status->value);

        // Only 50.00 of the 90.00 paid is left, so asking for the pre-discount 100.00 is refused.
        $this->expectException(RefundException::class);
        $refund('100.00');
    }

    #[Test]
    public function a_shipped_order_that_is_refunded_in_full_gives_its_use_of_the_code_back(): void
    {
        Notification::fake();
        $this->fill('50.00', 2);
        $coupon = Coupon::factory()->limited(total: 1, perCustomer: 1)->create(['code' => 'ONCE']);
        $this->apply('ONCE');
        $this->submit(['email' => 'ada@example.com', 'shipping_address' => $this->addressFields()])->assertRedirect();
        $order = Order::sole();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();

        $this->assertTrue(app(OrderLifecycle::class)->fulfil($order->refresh(), notifyCustomer: false));
        $this->app->make(OrderRefunder::class)->request(
            $order->refresh(),
            Money::parse($order->grand_total, 'USD'),
            new RefundRequest(token: (string) Str::uuid()),
        );

        $order->refresh();

        // The order stays completed: only its payment status says it was returned.
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame('refunded', $order->payment_status->value);
        $this->assertSame(0, app(CouponRedemptions::class)->total($coupon));

        // So neither limit blocks the customer or the next person.
        $again = $this->anotherShopperWith($coupon);
        $this->checkoutAs($again, 'ada@example.com')->assertRedirect()->assertSessionMissing('error');
    }

    #[Test]
    public function a_fully_refunded_order_gives_its_use_of_the_code_back(): void
    {
        Notification::fake();
        $this->fill('50.00', 2);
        $coupon = Coupon::factory()->limited(total: 1)->create(['code' => 'ONCE']);
        $this->apply('ONCE');
        $this->submit(['shipping_address' => $this->addressFields()])->assertRedirect();
        $order = Order::sole();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();

        $this->app->make(OrderRefunder::class)->request(
            $order->refresh(),
            Money::parse($order->grand_total, 'USD'),
            new RefundRequest(token: (string) Str::uuid()),
        );

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(0, app(CouponRedemptions::class)->total($coupon));
    }
}
