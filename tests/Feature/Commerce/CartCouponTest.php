<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * Putting a discount code on a cart, taking it off, and what the cart and checkout pages say about it.
 */
class CartCouponTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    private function cart(string $price = '50.00', int $quantity = 2): Cart
    {
        return $this->cartWith(Product::factory()->priced($price)->stocked(20)->create(['name' => 'Hoodie']), $quantity);
    }

    private function apply(string $code): TestResponse
    {
        return $this->post(route('shop.cart.coupon.store'), ['code' => $code]);
    }

    // --- Applying and removing ---------------------------------------------------------------------

    #[Test]
    public function a_code_is_put_on_the_cart_however_it_is_typed(): void
    {
        $cart = $this->cart();
        $coupon = Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);

        $this->apply('  save10 ')->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Code SAVE10 applied.');

        $this->assertSame($coupon->id, $cart->fresh()->coupon_id);
    }

    #[Test]
    public function applying_a_code_uses_nothing_up(): void
    {
        $this->cart();
        $coupon = Coupon::factory()->limited(total: 1)->create(['code' => 'ONCE']);

        $this->apply('ONCE')->assertSessionHasNoErrors();

        $this->assertSame(0, Order::query()->where('coupon_id', $coupon->id)->count());
    }

    #[Test]
    public function a_code_that_does_not_exist_is_refused(): void
    {
        $cart = $this->cart();

        $this->apply('NOPE')->assertSessionHasErrors(['code' => "That code isn't valid."]);

        $this->assertNull($cart->fresh()->coupon_id);
    }

    #[Test]
    public function something_that_is_not_a_code_is_refused_without_looking_for_it(): void
    {
        $cart = $this->cart();
        Coupon::factory()->create(['code' => 'REAL']);

        $this->apply("RE%' OR '1'='1")->assertSessionHasErrors(['code' => "That code isn't valid."]);
        $this->apply(str_repeat('A', 41))->assertSessionHasErrors('code');
        $this->apply('')->assertSessionHasErrors(['code' => 'Enter a discount code.']);

        $this->assertNull($cart->fresh()->coupon_id);
    }

    #[Test]
    public function a_code_that_cannot_be_used_says_why_and_is_not_kept(): void
    {
        $cart = $this->cart('50.00', 1);
        Coupon::factory()->minimumSpend('200.00')->create(['code' => 'BIGSPEND']);
        Coupon::factory()->expired()->create(['code' => 'OLD']);
        Coupon::factory()->inactive()->create(['code' => 'OFF']);

        $this->apply('BIGSPEND')->assertSessionHasErrors(['code' => 'Spend 200.00 USD or more to use that code.']);
        $this->apply('OLD')->assertSessionHasErrors(['code' => 'That code has expired.']);
        $this->apply('OFF')->assertSessionHasErrors(['code' => "That code isn't valid."]);

        $this->assertNull($cart->fresh()->coupon_id);
    }

    #[Test]
    public function a_code_that_would_save_nothing_is_refused(): void
    {
        $cart = $this->cart('10.00', 1);
        Coupon::factory()->percent('0.0001')->create(['code' => 'TINY']);

        $this->apply('TINY')->assertSessionHasErrors(['code' => "That code wouldn't take anything off your cart."]);

        $this->assertNull($cart->fresh()->coupon_id);
    }

    #[Test]
    public function a_code_limited_to_things_that_were_deleted_applies_to_nothing(): void
    {
        $cart = $this->cart();
        $gone = Product::factory()->priced('10.00')->create();
        Coupon::factory()->forProducts([$gone])->create(['code' => 'ORPHAN']);
        $gone->delete();

        $this->apply('ORPHAN')->assertSessionHasErrors(['code' => "That code doesn't apply to anything in your cart."]);

        $this->assertNull($cart->fresh()->coupon_id);
    }

    #[Test]
    public function a_new_code_replaces_the_one_on_the_cart(): void
    {
        $cart = $this->cart();
        $first = Coupon::factory()->create(['code' => 'FIRST']);
        $second = Coupon::factory()->create(['code' => 'SECOND']);

        $this->apply('FIRST');
        $this->assertSame($first->id, $cart->fresh()->coupon_id);

        $this->apply('SECOND');
        $this->assertSame($second->id, $cart->fresh()->coupon_id);
    }

    #[Test]
    public function a_refused_code_leaves_the_one_already_on_the_cart(): void
    {
        $cart = $this->cart();
        $first = Coupon::factory()->create(['code' => 'FIRST']);

        $this->apply('FIRST');
        $this->apply('NOPE')->assertSessionHasErrors('code');

        $this->assertSame($first->id, $cart->fresh()->coupon_id);
    }

    #[Test]
    public function a_code_can_be_removed(): void
    {
        $cart = $this->cart();
        Coupon::factory()->create(['code' => 'SAVE']);
        $this->apply('SAVE');

        $this->delete(route('shop.cart.coupon.destroy'))->assertRedirect()->assertSessionHas('success');

        $this->assertNull($cart->fresh()->coupon_id);
    }

    #[Test]
    public function removing_a_code_when_there_is_none_is_harmless(): void
    {
        $this->cart();

        $this->delete(route('shop.cart.coupon.destroy'))->assertRedirect()->assertSessionHasNoErrors();
    }

    #[Test]
    public function there_is_nothing_to_discount_in_an_empty_cart(): void
    {
        Coupon::factory()->create(['code' => 'SAVE']);

        $this->apply('SAVE')->assertSessionHasErrors(['code' => 'Add something to your cart before using a code.']);

        $cart = $this->cart();
        $cart->items()->delete();

        $this->apply('SAVE')->assertSessionHasErrors(['code' => 'Add something to your cart before using a code.']);
    }

    #[Test]
    public function a_cart_with_something_unavailable_cannot_take_a_code(): void
    {
        $cart = $this->cart();
        Product::query()->update(['is_active' => false]);
        Coupon::factory()->create(['code' => 'SAVE']);

        $this->apply('SAVE')->assertSessionHasErrors('code');

        $this->assertNull($cart->fresh()->coupon_id);
    }

    #[Test]
    public function a_code_goes_on_the_shoppers_own_cart_only(): void
    {
        $mine = $this->cart();
        $someoneElses = Cart::create(['session_id' => 'another-browser', 'currency' => 'USD']);
        Coupon::factory()->create(['code' => 'SAVE']);

        $this->apply('SAVE');

        $this->assertNotNull($mine->fresh()->coupon_id);
        $this->assertNull($someoneElses->fresh()->coupon_id);
    }

    #[Test]
    public function a_signed_in_customer_is_held_to_the_per_customer_limit_when_applying(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->limited(perCustomer: 1)->create(['code' => 'ONEEACH']);
        Order::factory()->create(['coupon_id' => $coupon->id, 'user_id' => $user->id, 'status' => 'processing']);
        $cart = $this->cartWith(Product::factory()->priced('50.00')->stocked(9)->create(), 1, $user);

        $this->actingAs($user)->post(route('shop.cart.coupon.store'), ['code' => 'ONEEACH'])
            ->assertSessionHasErrors(['code' => "You've already used that code."]);

        $this->assertNull($cart->fresh()->coupon_id);
    }

    #[Test]
    public function trying_codes_is_slowed_down_so_they_cannot_be_guessed(): void
    {
        $this->cart();

        foreach (range(1, 10) as $attempt) {
            $this->apply("GUESS{$attempt}")->assertSessionHasErrors('code');
        }

        $this->apply('GUESS11')->assertStatus(429);
    }

    // --- What the pages show -----------------------------------------------------------------------

    #[Test]
    public function the_cart_page_shows_the_code_and_what_it_takes_off(): void
    {
        $this->cart('50.00', 2);
        Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('coupon.code', 'SAVE10')
            ->where('coupon.applied', true)
            ->where('coupon.problem', null)
            ->where('coupon.discount', '10.00')
            ->where('coupon.free_shipping', false));
    }

    #[Test]
    public function the_cart_page_has_no_code_when_there_is_none(): void
    {
        $this->cart();

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('coupon', null));
    }

    #[Test]
    public function a_free_shipping_code_is_labelled_as_such(): void
    {
        $this->cart();
        ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
        Coupon::factory()->freeShipping()->create(['code' => 'SHIPFREE']);
        $this->apply('SHIPFREE');

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('coupon.code', 'SHIPFREE')
            ->where('coupon.applied', true)
            ->where('coupon.free_shipping', true));
    }

    #[Test]
    public function the_cart_page_says_when_a_code_has_stopped_working(): void
    {
        $this->cart();
        $coupon = Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');

        $coupon->update(['ends_at' => now()->subMinute()]);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('coupon.code', 'SAVE10')
            ->where('coupon.applied', false)
            ->where('coupon.problem', 'That code has expired.')
            ->where('coupon.discount', null));
    }

    #[Test]
    public function changing_the_cart_can_make_a_code_stop_applying(): void
    {
        $cart = $this->cart('50.00', 2);
        Coupon::factory()->minimumSpend('80.00')->create(['code' => 'BIG']);
        $this->apply('BIG')->assertSessionHasNoErrors();

        $cart->items()->first()->update(['quantity' => 1]);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('coupon.applied', false)
            ->where('coupon.problem', 'Spend 80.00 USD or more to use that code.'));
    }

    #[Test]
    public function the_checkout_quote_carries_the_code_and_the_totals_after_it(): void
    {
        $this->cart('50.00', 2);
        Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.coupon.code', 'SAVE10')
            ->where('quote.coupon.applied', true)
            ->where('quote.subtotal', '100.00')
            ->where('quote.discount_total', '10.00')
            ->where('quote.grand_total', '90.00'));
    }

    #[Test]
    public function the_checkout_quote_says_why_a_code_is_not_being_used(): void
    {
        $this->cart();
        $coupon = Coupon::factory()->percent('10')->create(['code' => 'SAVE10']);
        $this->apply('SAVE10');
        $coupon->update(['is_active' => false]);

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.coupon.applied', false)
            ->where('quote.coupon.problem', "That code isn't valid.")
            ->where('quote.discount_total', '0.00')
            ->where('quote.grand_total', '100.00'));
    }

    #[Test]
    public function a_coupon_that_is_deleted_drops_off_the_cart(): void
    {
        $cart = $this->cart();
        $coupon = Coupon::factory()->create(['code' => 'SAVE']);
        $this->apply('SAVE');

        $coupon->delete();

        $this->assertNull($cart->fresh()->coupon_id);
        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('coupon', null));
    }
}
