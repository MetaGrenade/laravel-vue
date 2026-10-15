<?php

namespace Tests\Feature\Tebex;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\TebexPackage;
use App\Models\User;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutException;
use App\Support\Commerce\OrderPricer;
use App\Support\Commerce\PendingOrderExpirer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTebexBaskets;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * Checking out a cart of Tebex packages: the order, the basket at Tebex and where the customer is sent.
 */
class TebexCheckoutTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use RefreshDatabase;

    private FakeTebexBaskets $tebex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        $this->setUpTebex();
        $this->sellThroughTebex();
        Notification::fake();

        $this->tebex = $this->fakeTebexBaskets();
    }

    private function package(string $ident = 'vip-rank', string $price = '10.00', array $attributes = []): TebexPackage
    {
        return TebexPackage::factory()->priced($price)->create(['ident' => $ident, 'name' => Str::headline($ident)] + $attributes);
    }

    private function inCart(TebexPackage $package, int $quantity = 1): void
    {
        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => $quantity])->assertSessionHas('success');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkout(array $data = [])
    {
        return $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'name' => 'Ada Buyer', 'token' => (string) Str::uuid(), ...$data]);
    }

    // --- The order and the basket -----------------------------------------------------------------------

    #[Test]
    public function checking_out_makes_a_pending_order_and_a_basket_and_sends_the_customer_to_tebex(): void
    {
        $vip = $this->package('vip-rank', '10.00');
        $pet = $this->package('pet-cat', '2.50');
        $this->inCart($vip, 2);
        $this->inCart($pet, 1);

        $response = $this->checkout();

        $order = Order::query()->sole();
        $payment = Payment::query()->sole();

        $response->assertRedirect($payment->checkout_url);
        $this->assertSame("https://pay.tebex.io/{$payment->provider_reference}", $payment->checkout_url);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame('tebex', $order->payment_provider);
        $this->assertSame('USD', $order->currency);
        $this->assertSame('22.50', $order->subtotal);
        $this->assertSame('0.00', $order->tax_total);
        $this->assertSame('0.00', $order->shipping_total);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('22.50', $order->grand_total, 'the packages at the price Tebex quoted, before the tax Tebex adds');
        $this->assertSame('buyer@example.com', $order->customer_email);
        $this->assertSame('Ada Buyer', $order->customer_name);
        $this->assertNull($order->shipping_address);
        $this->assertNull($order->gift_card_id);
        $this->assertTrue($order->expires_at->isFuture());

        $items = $order->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame([$vip->id, $pet->id], $items->pluck('tebex_package_id')->all());
        $this->assertSame([null, null], $items->pluck('product_id')->all());
        $this->assertSame([2, 1], $items->pluck('quantity')->all());
        $this->assertSame(['10.00', '2.50'], $items->pluck('unit_price')->all());
        $this->assertSame(['20.00', '2.50'], $items->pluck('subtotal')->all());
        $this->assertSame(['Vip Rank', 'Pet Cat'], $items->pluck('description')->all());
        $this->assertSame([false, false], $items->pluck('requires_shipping')->all());
        $this->assertSame([false, false], $items->pluck('delivers_keys')->all());
        $this->assertSame('vip-rank', $items[0]->metadata['ident']);

        $this->assertSame('tebex', $payment->provider);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('22.50', $payment->amount);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame(32, strlen($payment->raw['nonce']));
        $this->assertEquals(['currency' => 'USD', 'base_price' => '22.50', 'sales_tax' => '0.00', 'total_price' => '22.50'], $payment->raw['quoted']);
        $this->assertFalse($payment->raw['price_differs']);
    }

    #[Test]
    public function the_basket_says_where_to_come_back_to_and_what_identifies_the_order(): void
    {
        $this->inCart($this->package('vip-rank'), 2);
        $this->inCart($this->package('pet-cat', '2.50'));

        $this->checkout();

        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        $create = $this->tebex->calls('create');

        $this->assertCount(1, $create);
        $body = $create[0]['body'];
        $this->assertSame(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]), $body['complete_url']);
        $this->assertSame(route('shop.cart'), $body['cancel_url']);
        $this->assertSame(['order' => $order->public_id, 'nonce' => $payment->raw['nonce']], $body['custom']);
        $this->assertSame('127.0.0.1', $body['ip_address'], 'where the buyer is, not where the shop is');
        $this->assertTrue($body['complete_auto_redirect']);

        $adds = $this->tebex->calls('add');
        $this->assertSame([['package_id' => 'vip-rank', 'quantity' => 2], ['package_id' => 'pet-cat', 'quantity' => 1]], array_column($adds, 'body'));
        $this->assertSame("/api/baskets/{$payment->provider_reference}/packages", $adds[0]['path']);
    }

    #[Test]
    public function the_private_key_is_never_sent_with_a_basket(): void
    {
        $this->inCart($this->package());

        $this->checkout();

        foreach ($this->tebex->requests as $request) {
            $this->assertArrayNotHasKey('Authorization', $request['headers']);
            $this->assertStringNotContainsString('private-key', (string) json_encode($request));
        }
    }

    #[Test]
    public function the_cart_stays_until_the_order_is_paid(): void
    {
        $this->inCart($this->package(), 3);

        $this->checkout();

        $cart = Cart::query()->sole();
        $this->assertSame(CartManager::OPEN, $cart->status);
        $this->assertSame(3, CartItem::query()->sole()->quantity);
        $this->assertSame($cart->id, Order::query()->sole()->cart_id);
    }

    #[Test]
    public function a_signed_in_customer_owns_the_order(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->inCart($this->package());

        $this->checkout(['email' => 'ignored@example.com'])->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame($user->id, (int) $order->owner_id);
        $this->assertSame($user->email, $order->customer_email);
    }

    #[Test]
    public function no_address_is_asked_for_or_kept_even_when_the_shop_charges_tax_by_location(): void
    {
        TaxRate::factory()->create(['country' => 'GB', 'rate' => '20']);
        $this->inCart($this->package());

        $this->get(route('shop.checkout'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/Checkout')
            ->where('tebex', true)
            ->where('needsShipping', false)
            ->where('billingRequired', false)
            ->where('giftCardsEnabled', false)
            ->where('provider', 'Tebex')
            ->where('available', true)
            ->where('quote.subtotal', '10.00')
            ->where('quote.grand_total', '10.00')
            ->where('quote.tax.total', '0.00')
            ->where('quote.coupon', null)
            ->where('quote.gift_card', null)
            ->where('quote.error', null));

        // Posted anyway (a stale form), it is not asked for, validated or kept.
        $this->checkout(['shipping_address' => ['country' => 'ZZ'], 'billing_address' => ['country' => 'ZZ']])->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::query()->sole();
        $this->assertNull($order->shipping_address);
        $this->assertNull($order->billing_address);
        $this->assertSame('0.00', $order->tax_total);
    }

    #[Test]
    public function the_order_is_priced_again_from_the_copy_of_the_catalogue_whatever_the_cart_says(): void
    {
        $package = $this->package('vip-rank', '10.00');
        $this->inCart($package, 2);
        $package->update(['base_price' => '12.00', 'total_price' => '12.00']);

        $this->checkout();

        $this->assertSame('24.00', Order::query()->sole()->subtotal);
    }

    #[Test]
    public function pressing_the_button_twice_makes_one_order_and_one_basket(): void
    {
        $this->inCart($this->package());
        $token = (string) Str::uuid();

        $first = $this->checkout(['token' => $token]);
        $second = $this->checkout(['token' => $token]);

        $this->assertSame(1, Order::query()->count());
        $this->assertCount(1, $this->tebex->calls('create'));
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
    }

    // --- Tebex's price is not the shop's ---------------------------------------------------------------------

    #[Test]
    public function a_price_tebex_has_since_changed_is_recorded_not_refused(): void
    {
        Log::spy();
        $this->inCart($this->package('vip-rank', '10.00'), 2);
        $this->tebex->prices[TebexPackage::query()->sole()->tebex_id] = 12.0;

        $this->checkout()->assertRedirect();

        $payment = Payment::query()->sole();
        $this->assertSame('20.00', Order::query()->sole()->subtotal, 'the order is what the shop quoted');
        $this->assertTrue($payment->raw['price_differs']);
        $this->assertSame('24.00', $payment->raw['quoted']['base_price']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'different price') && $context['shop'] === '20.00' && $context['tebex'] === '24.00')->once();
    }

    #[Test]
    public function the_tax_tebex_quotes_is_kept_on_the_payment_and_not_added_to_the_order(): void
    {
        $this->tebex->taxRate = 0.2;
        $this->inCart($this->package('vip-rank', '10.00'), 2);

        $this->checkout()->assertRedirect();

        $this->assertSame('20.00', Order::query()->sole()->grand_total);
        $this->assertSame('4.00', Payment::query()->sole()->raw['quoted']['sales_tax']);
        $this->assertSame('24.00', Payment::query()->sole()->raw['quoted']['total_price']);
    }

    // --- What stops it ------------------------------------------------------------------------------------------

    #[Test]
    public function a_package_that_can_no_longer_be_bought_stops_the_order_before_anything_is_asked_of_tebex(): void
    {
        $package = $this->package();
        $this->inCart($package);
        $package->update(['removed_at' => now()]);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', 'Vip Rank is no longer available.');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame([], $this->tebex->requests);
    }

    #[Test]
    public function a_package_that_has_gained_choices_since_it_was_added_stops_the_order(): void
    {
        $package = $this->package();
        $this->inCart($package);
        $package->update(['variables' => [['identifier' => 'server']]]);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, 'needs choices'));
        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_package_that_has_become_free_stops_the_order_so_nothing_is_handed_out_unpaid(): void
    {
        $package = $this->package();
        $this->inCart($package);
        $package->update(['base_price' => '0.00', 'total_price' => '0.00']);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, 'is free'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame([], $this->tebex->requests);
    }

    #[Test]
    public function a_package_line_whose_package_cannot_be_found_is_refused_not_a_crash(): void
    {
        // Not reachable through the database (the link goes null if a package row is removed), but a cart read at
        // the wrong moment must never be an error page.
        $item = new CartItem(['tebex_package_id' => 424242, 'quantity' => 1, 'snapshot' => ['package' => ['name' => 'Gone']]]);
        $item->setRelation('tebexPackage', null);
        $cart = new Cart(['currency' => 'USD']);
        $cart->setRelation('items', collect([$item]));
        $cart->setRelation('coupon', null);
        $cart->setRelation('giftCard', null);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Gone is no longer available.');

        app(OrderPricer::class)->price($cart);
    }

    #[Test]
    public function a_quantity_beyond_what_the_package_allows_stops_the_order(): void
    {
        $package = $this->package('vip-rank', '10.00', ['user_limit' => 2]);
        $this->inCart($package, 2);
        $package->update(['user_limit' => 1]);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', 'You can buy between 1 and 1 of Vip Rank at a time.');
    }

    #[Test]
    public function a_cart_mixing_products_and_packages_cannot_be_checked_out(): void
    {
        $this->inCart($this->package());
        $product = Product::factory()->priced('5.00')->stocked(5)->create();
        $cart = Cart::query()->sole();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '5.00', 'total' => '5.00']);

        // (A product has to be shipped, so the form is filled in as for one.)
        $this->checkout(['shipping_address' => $this->addressFields()])->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, 'both products and Tebex packages'));
        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_cart_is_only_checked_out_through_the_provider_that_sells_what_it_holds(): void
    {
        $this->inCart($this->package());

        // The store went back to Stripe while the cart was waiting.
        config(['commerce.provider' => 'stripe']);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, 'not sold the way this shop takes payment now'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame([], $this->tebex->requests);
    }

    #[Test]
    public function a_cart_of_products_is_not_checked_out_through_tebex(): void
    {
        config(['commerce.provider' => 'stripe']);
        $this->cartWith(Product::factory()->priced('5.00')->stocked(5)->create(), 1);
        config(['commerce.provider' => 'tebex']);

        $this->checkout(['shipping_address' => $this->addressFields()])->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, 'not sold the way this shop takes payment now'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame([], $this->tebex->requests);
    }

    #[Test]
    public function checkout_is_unavailable_without_a_token(): void
    {
        $this->inCart($this->package());
        config(['tebex.public_token' => null]);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('checkoutAvailable', false));
        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', 'Checkout is not available right now. Please try again later.');
        $this->assertSame(0, Order::query()->count());
    }

    // --- When Tebex fails --------------------------------------------------------------------------------------------

    #[Test]
    public function when_the_basket_cannot_be_made_the_order_is_cancelled_and_the_customer_is_told(): void
    {
        $this->inCart($this->package());
        $this->tebex->failOn('create', 503);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', 'We could not start the payment. Please try again in a moment.');

        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(0, Payment::query()->count());
        $this->assertCount(1, $this->tebex->calls('create'), 'a call that may have made a basket is not repeated');
    }

    #[Test]
    public function when_a_package_cannot_be_put_in_the_basket_the_order_is_cancelled(): void
    {
        $this->inCart($this->package());
        $this->tebex->failOn('add', 400);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', 'We could not start the payment. Please try again in a moment.');

        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    #[Test]
    public function a_basket_priced_in_another_currency_than_the_order_is_not_offered(): void
    {
        $this->inCart($this->package());
        $this->tebex->currency = 'EUR';

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error');

        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    // --- Starting again -----------------------------------------------------------------------------------------------

    #[Test]
    public function starting_again_empties_the_earlier_basket_so_it_cannot_be_paid_and_makes_a_new_one(): void
    {
        $this->inCart($this->package(), 2);
        $this->checkout();
        $first = Payment::query()->sole()->provider_reference;

        $this->checkout()->assertRedirect();

        $orders = Order::query()->orderBy('id')->get();
        $payments = Payment::query()->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame(OrderStatus::Cancelled, $orders[0]->status);
        $this->assertSame(OrderStatus::Pending, $orders[1]->status);
        $this->assertNotSame($first, $payments[1]->provider_reference);
        $this->assertSame([], $this->tebex->baskets[$first]['lines'], 'the earlier basket has nothing left to pay for');
        $this->assertCount(1, $this->tebex->baskets[$payments[1]->provider_reference]['lines']);
        $this->assertSame(1, $this->tebex->calls('remove') === [] ? 0 : count($this->tebex->calls('remove')));
    }

    #[Test]
    public function an_earlier_basket_that_has_been_paid_is_not_replaced(): void
    {
        $this->inCart($this->package());
        $this->checkout();
        $first = Payment::query()->sole()->provider_reference;
        $this->tebex->markPaid($first);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, 'still being confirmed'));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status, 'left for the payment to settle');
        $this->assertCount(1, $this->tebex->calls('create'));
        $this->assertCount(0, $this->tebex->calls('remove'), 'a paid basket is not touched');
    }

    #[Test]
    public function an_earlier_basket_that_cannot_be_emptied_is_not_replaced(): void
    {
        $this->inCart($this->package());
        $this->checkout();
        $this->tebex->stubborn = true;

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, 'still being confirmed'));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status);
        $this->assertCount(1, $this->tebex->calls('create'));
    }

    #[Test]
    public function when_tebex_cannot_be_asked_about_the_earlier_basket_nothing_is_replaced(): void
    {
        $this->inCart($this->package());
        $this->checkout();
        $this->tebex->failOn('get', 503, 4);

        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, "couldn't close your earlier checkout"));

        $this->assertSame(1, Order::query()->count());
        $this->assertCount(1, $this->tebex->calls('create'));
    }

    // --- Running out of time ----------------------------------------------------------------------------------------

    #[Test]
    public function an_unpaid_order_past_its_window_is_cancelled_and_its_basket_emptied(): void
    {
        $this->inCart($this->package());
        $this->checkout();
        $ident = Payment::query()->sole()->provider_reference;

        $this->travel(61)->minutes();
        $result = app(PendingOrderExpirer::class)->run();

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame([], $this->tebex->baskets[$ident]['lines'], 'it cannot be paid after the order is gone');
    }

    // --- Looking at the order afterwards -----------------------------------------------------------------------------

    #[Test]
    public function the_customers_order_page_shows_the_packages_while_the_payment_is_awaited(): void
    {
        $this->inCart($this->package('vip-rank', '10.00'), 2);
        $this->checkout();
        $order = Order::query()->sole();

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/CheckoutComplete')
                ->where('order.status', 'pending')
                ->where('order.grand_total', '20.00')
                ->where('order.items.0.description', 'Vip Rank')
                ->where('order.items.0.quantity', 2)
                ->where('downloadsPending', false)
                ->where('licenceKeysPending', false));

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status, 'coming back from Tebex settles nothing: only its webhook does');
    }

    #[Test]
    public function staff_see_the_order_with_its_packages_and_the_tebex_payment(): void
    {
        $this->inCart($this->package('vip-rank', '10.00'), 2);
        $this->checkout();
        $order = Order::query()->sole();
        $this->seed(RolePermissionSeeder::class);

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('acp.commerce.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.items.0.description', 'Vip Rank')
                ->where('payments.0.provider', 'tebex'));
    }
}
