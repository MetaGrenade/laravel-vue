<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutException;
use App\Support\Commerce\CustomerDetails;
use App\Support\Commerce\Destination;
use App\Support\Commerce\OrderPricer;
use App\Support\Commerce\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * What a discount code does to a price: how much comes off, which lines it touches, what tax is
 * charged afterwards, and every reason a code is turned down.
 */
class CouponPricingTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();

        // Standard shipping is 5.00 and everything, shipping included, carries 20% VAT.
        ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20']);
    }

    /**
     * A cart holding each product with its quantity.
     *
     * @param  list<array{0: Product, 1: int}>  $lines
     */
    private function cartOf(array $lines): Cart
    {
        $cart = $this->cartWith($lines[0][0], $lines[0][1]);

        foreach (array_slice($lines, 1) as [$product, $quantity]) {
            CartManager::addItem($cart, $product, null, $product->prices()->first(), $quantity);
        }

        return $cart->fresh();
    }

    private function price(Cart $cart, ?Coupon $coupon, bool $strict = false, ?CustomerDetails $customer = null): Pricing
    {
        $cart->update(['coupon_id' => $coupon?->id]);

        return app(OrderPricer::class)->price($cart->fresh(), Destination::make('GB', null), null, null, $strict, $customer);
    }

    private function hoodies(string $price = '50.00', int $quantity = 2): Cart
    {
        return $this->cartWith(Product::factory()->priced($price)->stocked(50)->create(['name' => 'Hoodie']), $quantity);
    }

    // --- How much comes off ------------------------------------------------------------------------

    #[Test]
    public function a_percentage_comes_off_the_items_and_tax_is_charged_on_what_is_left(): void
    {
        $pricing = $this->price($this->hoodies('50.00', 2), Coupon::factory()->percent('10')->create());

        $this->assertSame('100.00', $pricing->subtotal->toDecimal());
        $this->assertSame('10.00', $pricing->discount->toDecimal());
        // 20% of the 90.00 left on the items, and of the 5.00 shipping.
        $this->assertSame('18.00', $pricing->itemTax->toDecimal());
        $this->assertSame('1.00', $pricing->shippingTax->toDecimal());
        $this->assertSame('114.00', $pricing->grandTotal->toDecimal(), '100.00 + 5.00 shipping + 19.00 tax - 10.00');
    }

    #[Test]
    public function the_totals_without_a_code_are_unchanged(): void
    {
        $pricing = $this->price($this->hoodies('50.00', 2), null);

        $this->assertSame('0.00', $pricing->discount->toDecimal());
        $this->assertSame('126.00', $pricing->grandTotal->toDecimal());
        $this->assertNull($pricing->couponCode);
        $this->assertNull($pricing->applied);
    }

    #[Test]
    public function a_fixed_amount_comes_off_the_items(): void
    {
        $pricing = $this->price($this->hoodies('50.00', 2), Coupon::factory()->fixed('15.00')->create());

        $this->assertSame('15.00', $pricing->discount->toDecimal());
        // 20% of 85.00 and of 5.00.
        $this->assertSame('17.00', $pricing->itemTax->toDecimal());
        $this->assertSame('108.00', $pricing->grandTotal->toDecimal(), '100.00 + 5.00 shipping + 18.00 tax - 15.00');
    }

    #[Test]
    public function a_fixed_amount_never_takes_off_more_than_the_items_cost(): void
    {
        $pricing = $this->price($this->hoodies('10.00', 1), Coupon::factory()->fixed('500.00')->create());

        $this->assertSame('10.00', $pricing->discount->toDecimal());
        // Only the shipping and its tax are left to pay.
        $this->assertSame('6.00', $pricing->grandTotal->toDecimal());
    }

    #[Test]
    public function a_percentage_is_rounded_half_up_to_the_cent(): void
    {
        // 15% of 19.99 is 2.9985.
        $pricing = $this->price($this->hoodies('19.99', 1), Coupon::factory()->percent('15')->create());

        $this->assertSame('3.00', $pricing->discount->toDecimal());
    }

    #[Test]
    public function a_percentage_too_small_to_save_a_cent_is_refused_rather_than_used(): void
    {
        // 0.0001% of 10.00 is a hundred-thousandth of a cent: it rounds to nothing.
        $coupon = Coupon::factory()->percent('0.0001')->limited(total: 1)->create();

        $pricing = $this->price($this->hoodies('10.00', 1), $coupon);

        $this->assertSame("That code wouldn't take anything off your cart.", $pricing->couponProblem);
        $this->assertNull($pricing->applied);
        $this->assertSame('0.00', $pricing->discount->toDecimal());
    }

    #[Test]
    public function placing_an_order_refuses_a_code_that_would_save_nothing(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage("That code wouldn't take anything off your cart.");

        $this->price($this->hoodies('10.00', 1), Coupon::factory()->percent('0.0001')->create(), strict: true);
    }

    #[Test]
    public function the_smallest_percentage_that_saves_a_cent_is_accepted(): void
    {
        // 0.05% of 10.00 is 0.005, which rounds half up to a cent.
        $pricing = $this->price($this->hoodies('10.00', 1), Coupon::factory()->percent('0.05')->create());

        $this->assertNull($pricing->couponProblem);
        $this->assertSame('0.01', $pricing->discount->toDecimal());
    }

    #[Test]
    public function a_percentage_can_have_decimals(): void
    {
        $pricing = $this->price($this->hoodies('100.00', 1), Coupon::factory()->percent('12.5')->create());

        $this->assertSame('12.50', $pricing->discount->toDecimal());
    }

    #[Test]
    public function the_discount_is_shared_between_lines_so_they_add_up_exactly(): void
    {
        $cart = $this->cartOf([
            [Product::factory()->priced('33.33')->stocked(9)->create(), 1],
            [Product::factory()->priced('12.34')->stocked(9)->create(), 3],
            [Product::factory()->priced('0.99')->stocked(9)->create(), 7],
        ]);

        $pricing = $this->price($cart, Coupon::factory()->percent('17.5')->create());

        $shared = array_reduce($pricing->lines, fn (string $carry, $line) => bcadd($carry, $line->discount->toDecimal(), 2), '0.00');

        $this->assertSame($pricing->discount->toDecimal(), $shared, 'the lines add up to the discount to the cent');
        $this->assertSame(
            bcsub($pricing->subtotal->toDecimal(), $pricing->discount->toDecimal(), 2),
            array_reduce($pricing->lines, fn (string $carry, $line) => bcadd($carry, $line->net()->toDecimal(), 2), '0.00'),
        );
    }

    #[Test]
    #[DataProvider('oddAmounts')]
    public function lines_always_add_up_whatever_the_prices(string $first, string $second, string $percent): void
    {
        $cart = $this->cartOf([
            [Product::factory()->priced($first)->stocked(9)->create(), 1],
            [Product::factory()->priced($second)->stocked(9)->create(), 2],
        ]);

        $pricing = $this->price($cart, Coupon::factory()->percent($percent)->create());

        $this->assertSame(
            $pricing->discount->minor,
            array_sum(array_map(fn ($line) => $line->discount->minor, $pricing->lines)),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function oddAmounts(): array
    {
        return [
            'thirds' => ['10.00', '20.00', '33.3333'],
            'pennies' => ['0.01', '0.02', '50'],
            'awkward' => ['19.99', '7.77', '8.875'],
            'all of it' => ['5.55', '4.45', '100'],
        ];
    }

    // --- Which lines it touches --------------------------------------------------------------------

    #[Test]
    public function a_code_limited_to_products_only_discounts_those(): void
    {
        $hoodie = Product::factory()->priced('40.00')->stocked(9)->create();
        $mug = Product::factory()->priced('10.00')->stocked(9)->create();
        $cart = $this->cartOf([[$hoodie, 1], [$mug, 1]]);

        $pricing = $this->price($cart, Coupon::factory()->percent('50')->forProducts([$mug])->create());

        $this->assertSame('5.00', $pricing->discount->toDecimal(), 'half of the mug only');
        $this->assertSame(['0.00', '5.00'], array_map(fn ($line) => $line->discount->toDecimal(), $pricing->lines));
    }

    #[Test]
    public function a_code_limited_to_categories_discounts_products_in_them(): void
    {
        $clothing = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $hoodie = Product::factory()->priced('40.00')->stocked(9)->create();
        $hoodie->categories()->attach($clothing);
        $mug = Product::factory()->priced('10.00')->stocked(9)->create();
        $cart = $this->cartOf([[$hoodie, 1], [$mug, 1]]);

        $pricing = $this->price($cart, Coupon::factory()->percent('25')->forCategories([$clothing])->create());

        $this->assertSame('10.00', $pricing->discount->toDecimal());
        $this->assertSame(['10.00', '0.00'], array_map(fn ($line) => $line->discount->toDecimal(), $pricing->lines));
    }

    #[Test]
    public function products_and_categories_together_widen_what_a_code_applies_to(): void
    {
        $clothing = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $hoodie = Product::factory()->priced('40.00')->stocked(9)->create();
        $hoodie->categories()->attach($clothing);
        $mug = Product::factory()->priced('10.00')->stocked(9)->create();
        $poster = Product::factory()->priced('20.00')->stocked(9)->create();
        $cart = $this->cartOf([[$hoodie, 1], [$mug, 1], [$poster, 1]]);

        $coupon = Coupon::factory()->percent('10')->forCategories([$clothing])->forProducts([$mug])->create();
        $pricing = $this->price($cart, $coupon);

        $this->assertSame(['4.00', '1.00', '0.00'], array_map(fn ($line) => $line->discount->toDecimal(), $pricing->lines));
    }

    #[Test]
    public function a_code_limited_to_products_that_are_not_in_the_cart_is_refused(): void
    {
        $other = Product::factory()->priced('10.00')->stocked(9)->create();
        $pricing = $this->price($this->hoodies(), Coupon::factory()->forProducts([$other])->create());

        $this->assertSame("That code doesn't apply to anything in your cart.", $pricing->couponProblem);
        $this->assertNull($pricing->applied);
        $this->assertSame('0.00', $pricing->discount->toDecimal());
    }

    #[Test]
    public function deleting_the_last_product_a_code_is_limited_to_does_not_make_it_store_wide(): void
    {
        $mug = Product::factory()->priced('10.00')->stocked(9)->create();
        $coupon = Coupon::factory()->percent('50')->forProducts([$mug])->create();

        $mug->delete();

        $this->assertSame(0, $coupon->products()->count(), 'the limit row went with the product');
        $this->assertTrue($coupon->fresh()->is_restricted, 'but the code is still limited');

        $pricing = $this->price($this->hoodies('50.00', 2), $coupon);

        $this->assertSame("That code doesn't apply to anything in your cart.", $pricing->couponProblem);
        $this->assertNull($pricing->applied);
        $this->assertSame('0.00', $pricing->discount->toDecimal());
    }

    #[Test]
    public function deleting_the_last_category_a_code_is_limited_to_does_not_make_it_store_wide(): void
    {
        $clothing = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $coupon = Coupon::factory()->percent('50')->forCategories([$clothing])->create();

        $clothing->delete();

        $this->assertSame("That code doesn't apply to anything in your cart.", $this->price($this->hoodies(), $coupon)->couponProblem);
    }

    #[Test]
    public function deleting_one_of_several_targets_leaves_the_code_on_the_others(): void
    {
        $mug = Product::factory()->priced('10.00')->stocked(9)->create();
        $hoodie = Product::factory()->priced('40.00')->stocked(9)->create();
        $coupon = Coupon::factory()->percent('50')->forProducts([$mug, $hoodie])->create();

        $mug->delete();

        $pricing = $this->price($this->cartOf([[$hoodie, 1]]), $coupon);

        $this->assertNull($pricing->couponProblem);
        $this->assertSame('20.00', $pricing->discount->toDecimal());
    }

    #[Test]
    public function the_minimum_spend_counts_only_the_items_the_code_applies_to(): void
    {
        $hoodie = Product::factory()->priced('40.00')->stocked(9)->create();
        $mug = Product::factory()->priced('10.00')->stocked(9)->create();
        $cart = $this->cartOf([[$hoodie, 1], [$mug, 1]]);

        // 50.00 in the cart, but only 10.00 of it is the mug.
        $coupon = Coupon::factory()->percent('10')->minimumSpend('25.00')->forProducts([$mug])->create();

        $this->assertSame('Spend 25.00 USD or more to use that code.', $this->price($cart, $coupon)->couponProblem);
    }

    #[Test]
    public function a_discount_on_something_untaxed_does_not_lower_the_tax(): void
    {
        $taxed = Product::factory()->priced('40.00')->stocked(9)->create();
        $untaxed = Product::factory()->priced('10.00')->untaxed()->stocked(9)->create();
        $cart = $this->cartOf([[$taxed, 1], [$untaxed, 1]]);

        $coupon = Coupon::factory()->percent('100')->forProducts([$untaxed])->create();
        $pricing = $this->price($cart, $coupon);

        $this->assertSame('10.00', $pricing->discount->toDecimal());
        // Tax is still 20% of the 40.00 taxed item, and of the shipping.
        $this->assertSame('8.00', $pricing->itemTax->toDecimal());
    }

    // --- Free shipping ----------------------------------------------------------------------------

    #[Test]
    public function free_shipping_waives_the_shipping_charge_and_the_tax_on_it(): void
    {
        $pricing = $this->price($this->hoodies('50.00', 2), Coupon::factory()->freeShipping()->create());

        $this->assertSame('5.00', $pricing->shippingTotal()->toDecimal(), 'the rate is still shown');
        $this->assertSame('5.00', $pricing->shippingDiscount()->toDecimal());
        $this->assertSame('5.00', $pricing->discount->toDecimal());
        $this->assertSame('0.00', $pricing->shippingTax->toDecimal());
        $this->assertSame('120.00', $pricing->grandTotal->toDecimal(), '100.00 + 20.00 tax on the items');
        $this->assertSame(['0.00'], array_map(fn ($line) => $line->discount->toDecimal(), $pricing->lines));
    }

    #[Test]
    public function free_shipping_needs_something_to_ship(): void
    {
        $cart = $this->cartWith(Product::factory()->priced('10.00')->digital()->create());

        $pricing = $this->price($cart, Coupon::factory()->freeShipping()->create());

        $this->assertSame('That code only applies to orders that are shipped.', $pricing->couponProblem);
    }

    #[Test]
    public function free_shipping_is_refused_when_shipping_is_already_free(): void
    {
        ShippingZone::query()->delete();
        ShippingZone::factory()->serving(['GB'])->withRate('Free', '0.00')->create();

        $pricing = $this->price($this->hoodies(), Coupon::factory()->freeShipping()->create());

        $this->assertSame('Shipping is already free on this order.', $pricing->couponProblem);
        $this->assertNull($pricing->applied);
    }

    #[Test]
    public function free_shipping_is_refused_when_the_shop_charges_no_shipping(): void
    {
        ShippingZone::query()->delete();

        $pricing = $this->price($this->hoodies(), Coupon::factory()->freeShipping()->create());

        $this->assertSame('Shipping is already free on this order.', $pricing->couponProblem);
    }

    #[Test]
    public function free_shipping_is_not_judged_before_the_shopper_has_given_an_address(): void
    {
        // On the cart page the shipping charge is not known yet: zero there is only a placeholder.
        $coupon = Coupon::factory()->freeShipping()->create();
        $cart = $this->hoodies();
        $cart->update(['coupon_id' => $coupon->id]);

        $pricing = app(OrderPricer::class)->price($cart->fresh());

        $this->assertNull($pricing->couponProblem);
        $this->assertNotNull($pricing->applied);
    }

    #[Test]
    public function free_shipping_is_refused_at_checkout_when_the_chosen_rate_is_already_free(): void
    {
        ShippingZone::query()->delete();
        ShippingZone::factory()->serving(['GB'])->withRate('Free', '0.00')->create();

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Shipping is already free on this order.');

        $this->price($this->hoodies(), Coupon::factory()->freeShipping()->create(), strict: true);
    }

    #[Test]
    public function free_shipping_can_have_a_minimum_spend(): void
    {
        $coupon = Coupon::factory()->freeShipping()->minimumSpend('150.00')->create();

        $this->assertSame('Spend 150.00 USD or more to use that code.', $this->price($this->hoodies('50.00', 2), $coupon)->couponProblem);
        $this->assertNull($this->price($this->hoodies('100.00', 2), $coupon)->couponProblem);
    }

    #[Test]
    public function the_shipping_rates_offered_do_not_depend_on_the_code(): void
    {
        // A "free over 150.00" style rate keys off the cart before any discount.
        ShippingZone::query()->delete();
        ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->withRate('Bulk', '0.00', '100.00')->create();

        $with = $this->price($this->hoodies('50.00', 2), Coupon::factory()->percent('50')->create());

        $this->assertCount(2, $with->shippingOptions, 'the cart is 100.00 before the code, so Bulk is still offered');
    }

    // --- When a code is turned down ----------------------------------------------------------------

    #[Test]
    #[DataProvider('unusableCodes')]
    public function a_code_that_cannot_be_used_says_so_and_takes_nothing_off(callable $make, string $problem): void
    {
        $pricing = $this->price($this->hoodies(), $make());

        $this->assertSame($problem, $pricing->couponProblem);
        $this->assertNull($pricing->applied);
        $this->assertSame('0.00', $pricing->discount->toDecimal());
        $this->assertSame('126.00', $pricing->grandTotal->toDecimal());
        $this->assertNotNull($pricing->couponCode, 'the shopper still sees which code it is');
    }

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function unusableCodes(): array
    {
        return [
            'switched off' => [fn () => Coupon::factory()->inactive()->create(), "That code isn't valid."],
            'not started' => [fn () => Coupon::factory()->scheduled()->create(), "That code isn't valid."],
            'expired' => [fn () => Coupon::factory()->expired()->create(), 'That code has expired.'],
            'in another currency' => [fn () => Coupon::factory()->fixed('5.00', 'EUR')->create(), "That code isn't valid."],
            'below the minimum' => [fn () => Coupon::factory()->minimumSpend('500.00')->create(), 'Spend 500.00 USD or more to use that code.'],
        ];
    }

    #[Test]
    public function a_code_that_has_ended_stops_at_the_moment_it_ends(): void
    {
        $coupon = Coupon::factory()->create(['ends_at' => now()->addMinute()]);
        $cart = $this->hoodies();

        $this->assertNull($this->price($cart, $coupon)->couponProblem);

        $this->travel(2)->minutes();

        $this->assertSame('That code has expired.', $this->price($cart, $coupon)->couponProblem);
    }

    #[Test]
    public function a_code_that_would_make_the_order_free_is_refused(): void
    {
        // Free of everything: no shipping, no tax, everything discounted.
        ShippingZone::query()->delete();
        TaxRate::query()->delete();
        $cart = $this->hoodies('10.00', 1);

        $pricing = $this->price($cart, Coupon::factory()->percent('100')->create());

        $this->assertSame("That code can't be used on this order because it would make it free.", $pricing->couponProblem);
        $this->assertNull($pricing->applied);
        $this->assertSame('10.00', $pricing->grandTotal->toDecimal());
    }

    #[Test]
    public function a_hundred_percent_code_is_fine_while_shipping_is_still_to_pay(): void
    {
        $pricing = $this->price($this->hoodies('10.00', 1), Coupon::factory()->percent('100')->create());

        $this->assertNull($pricing->couponProblem);
        $this->assertSame('10.00', $pricing->discount->toDecimal());
        $this->assertSame('6.00', $pricing->grandTotal->toDecimal());
    }

    #[Test]
    public function placing_an_order_refuses_a_code_that_cannot_be_used(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage("Your discount code GONE can't be used. That code has expired.");

        $this->price($this->hoodies(), Coupon::factory()->expired()->create(['code' => 'GONE']), strict: true);
    }

    // --- How many times it can be used -------------------------------------------------------------

    private function usedOn(Coupon $coupon, string $status = 'processing', ?string $email = null, ?User $user = null, ?Cart $cart = null): Order
    {
        return Order::factory()->create(array_filter([
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'status' => $status,
            'customer_email' => $email,
            'user_id' => $user?->id,
            'cart_id' => $cart?->id,
        ], fn ($value) => $value !== null));
    }

    #[Test]
    public function a_code_stops_working_once_it_has_been_used_as_many_times_as_allowed(): void
    {
        $coupon = Coupon::factory()->limited(total: 2)->create();
        $this->usedOn($coupon);

        $this->assertNull($this->price($this->hoodies(), $coupon)->couponProblem);

        $this->usedOn($coupon, 'pending');

        $this->assertSame('That code has been fully redeemed.', $this->price($this->hoodies(), $coupon)->couponProblem);
    }

    #[Test]
    public function a_cancelled_order_gives_its_use_back(): void
    {
        $coupon = Coupon::factory()->limited(total: 1)->create();
        $order = $this->usedOn($coupon, 'pending');

        $this->assertSame('That code has been fully redeemed.', $this->price($this->hoodies(), $coupon)->couponProblem);

        $order->update(['status' => 'cancelled']);

        $this->assertNull($this->price($this->hoodies(), $coupon)->couponProblem);
    }

    #[Test]
    public function the_shoppers_own_unpaid_order_from_this_cart_does_not_use_up_the_code(): void
    {
        // They went to the payment page and came back: the next checkout replaces that order.
        $coupon = Coupon::factory()->limited(total: 1)->create();
        $cart = $this->hoodies();
        $this->usedOn($coupon, 'pending', cart: $cart);

        $this->assertNull($this->price($cart, $coupon)->couponProblem);
    }

    #[Test]
    public function a_paid_order_from_this_cart_still_counts(): void
    {
        $coupon = Coupon::factory()->limited(total: 1)->create();
        $cart = $this->hoodies();
        $this->usedOn($coupon, 'processing', cart: $cart);

        $this->assertSame('That code has been fully redeemed.', $this->price($cart, $coupon)->couponProblem);
    }

    #[Test]
    public function an_unpaid_order_whose_cart_has_gone_still_holds_its_use(): void
    {
        // Deleting a cart leaves its orders with no cart; that must not make their use disappear.
        $coupon = Coupon::factory()->limited(total: 1)->create();
        $gone = $this->hoodies();
        $order = $this->usedOn($coupon, 'pending', cart: $gone);
        $gone->delete();

        $this->assertNull($order->fresh()->cart_id);
        $this->assertSame('That code has been fully redeemed.', $this->price($this->hoodies(), $coupon)->couponProblem);
    }

    #[Test]
    public function an_order_refunded_in_full_gives_its_use_back_even_though_it_was_fulfilled(): void
    {
        // Refunding a fulfilled order changes only its payment status: it stays "completed".
        $coupon = Coupon::factory()->limited(total: 1, perCustomer: 1)->create();
        $user = User::factory()->create();
        $order = $this->usedOn($coupon, 'completed', user: $user, email: $user->email);
        $customer = new CustomerDetails($user->email, 'Ada', $user);

        $this->assertSame('That code has been fully redeemed.', $this->price($this->hoodies(), $coupon, customer: $customer)->couponProblem);

        $order->update(['payment_status' => 'refunded']);

        $this->assertNull($this->price($this->hoodies(), $coupon, customer: $customer)->couponProblem, 'neither limit blocks them now');
    }

    #[Test]
    public function an_order_refunded_in_part_still_holds_its_use(): void
    {
        $coupon = Coupon::factory()->limited(total: 1)->create();
        $this->usedOn($coupon, 'completed')->update(['payment_status' => 'partially_refunded']);

        $this->assertSame('That code has been fully redeemed.', $this->price($this->hoodies(), $coupon)->couponProblem);
    }

    #[Test]
    public function a_customer_can_be_limited_to_using_a_code_a_set_number_of_times(): void
    {
        $coupon = Coupon::factory()->limited(perCustomer: 1)->create();
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $this->usedOn($coupon, user: $user, email: 'ada@example.com');
        $this->usedOn($coupon, email: 'someone@example.com');

        $asAda = new CustomerDetails('ada@example.com', 'Ada', $user);
        $asSomeoneElse = new CustomerDetails('new@example.com');

        $this->assertSame("You've already used that code.", $this->price($this->hoodies(), $coupon, customer: $asAda)->couponProblem);
        $this->assertNull($this->price($this->hoodies(), $coupon, customer: $asSomeoneElse)->couponProblem);
    }

    #[Test]
    public function a_guest_is_recognised_by_their_email_in_any_case(): void
    {
        $coupon = Coupon::factory()->limited(perCustomer: 1)->create();
        $this->usedOn($coupon, email: 'Ada@Example.com');

        $again = new CustomerDetails('ada@example.COM');

        $this->assertSame("You've already used that code.", $this->price($this->hoodies(), $coupon, customer: $again)->couponProblem);
    }

    #[Test]
    public function an_account_is_recognised_by_its_orders_even_with_another_email(): void
    {
        $coupon = Coupon::factory()->limited(perCustomer: 1)->create();
        $user = User::factory()->create();
        $this->usedOn($coupon, user: $user, email: 'old-address@example.com');

        $now = new CustomerDetails($user->email, 'Ada', $user);

        $this->assertSame("You've already used that code.", $this->price($this->hoodies(), $coupon, customer: $now)->couponProblem);
    }

    #[Test]
    public function the_per_customer_limit_is_not_checked_until_the_customer_is_known(): void
    {
        $coupon = Coupon::factory()->limited(perCustomer: 1)->create();
        $this->usedOn($coupon, email: 'ada@example.com');

        // A guest looking at the cart has not given an email yet; it is enforced when they check out.
        $this->assertNull($this->price($this->hoodies(), $coupon)->couponProblem);
    }
}
