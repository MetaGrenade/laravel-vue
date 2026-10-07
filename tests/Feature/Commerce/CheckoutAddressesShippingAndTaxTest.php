<?php

namespace Tests\Feature\Commerce;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use App\Notifications\OrderConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class CheckoutAddressesShippingAndTaxTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submit(array $overrides = []): TestResponse
    {
        return $this->post(route('shop.checkout.store'), $this->checkoutPayload($overrides));
    }

    private function ukShopWithVat(): void
    {
        ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20']);
    }

    private function physicalCart(string $price = '50.00', int $quantity = 2): Product
    {
        $product = Product::factory()->priced($price)->stocked(10)->create(['name' => 'Hoodie']);
        $this->cartWith($product, $quantity);

        return $product;
    }

    #[Test]
    public function the_order_records_shipping_tax_totals_and_where_it_goes(): void
    {
        $this->ukShopWithVat();
        $this->physicalCart('50.00', 2);

        $this->submit()->assertRedirect();

        $order = Order::sole();

        $this->assertSame('100.00', $order->subtotal);
        $this->assertSame('5.00', $order->shipping_total);
        $this->assertSame('21.00', $order->tax_total);
        $this->assertSame('126.00', $order->grand_total);
        $this->assertSame('Standard', $order->shipping_method);
        $this->assertSame('1 Test Street', $order->shipping_address['line1']);
        $this->assertSame('GB', $order->shipping_address['country']);
        $this->assertEquals($order->shipping_address, $order->billing_address, 'billing defaults to the shipping address');
        $this->assertSame([['name' => 'VAT', 'rate' => '20', 'amount' => '21.00']], $order->metadata['tax_lines']);
        $this->assertSame('20.00', $order->items->sole()->tax_total, 'the item tax sits on its line');
    }

    #[Test]
    public function stripe_is_asked_to_charge_shipping_and_tax_as_their_own_lines(): void
    {
        $this->ukShopWithVat();
        $this->physicalCart('50.00', 2);

        $this->submit()->assertRedirect();

        $params = $this->stripe->lastCreatedParams();
        $lines = collect($params['line_items']);

        $this->assertSame(
            ['Hoodie', 'Shipping — Standard', 'VAT (20%)'],
            $lines->map(fn ($line) => $line['price_data']['product_data']['name'])->all(),
        );
        $this->assertSame([5000, 500, 2100], $lines->map(fn ($line) => $line['price_data']['unit_amount'])->all());
        $this->assertSame(12600, $lines->sum(fn ($line) => $line['price_data']['unit_amount'] * $line['quantity']), 'Stripe charges exactly the order total');

        $this->assertSame('Ada Buyer', $params['payment_intent_data']['shipping']['name']);
        $this->assertSame('GB', $params['payment_intent_data']['shipping']['address']['country']);
        $this->assertSame('N1 1AA', $params['payment_intent_data']['shipping']['address']['postal_code']);
    }

    #[Test]
    public function a_payment_for_the_full_total_including_shipping_and_tax_is_accepted(): void
    {
        Notification::fake();
        $this->ukShopWithVat();
        $this->physicalCart('50.00', 2);
        $this->submit()->assertRedirect();
        $order = Order::sole();

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->firstOrFail())))->assertOk();

        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(12600, $this->stripe->sessions['cs_test_1']['amount_total']);
    }

    #[Test]
    public function free_shipping_and_untaxed_orders_send_no_extra_lines(): void
    {
        $this->physicalCart('10.00', 1);

        $this->submit()->assertRedirect();

        $this->assertCount(1, $this->stripe->lastCreatedParams()['line_items']);
    }

    #[Test]
    public function a_physical_cart_needs_a_shipping_address(): void
    {
        $this->physicalCart();

        $this->post(route('shop.checkout.store'), $this->checkoutPayload(['shipping_address' => null]))
            ->assertSessionHasErrors(['shipping_address']);

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function the_shipping_address_fields_are_validated(): void
    {
        $this->physicalCart();

        $this->submit(['shipping_address' => ['name' => '', 'line1' => '', 'city' => '', 'postal_code' => '', 'country' => 'ZZ']])
            ->assertSessionHasErrors([
                'shipping_address.name',
                'shipping_address.line1',
                'shipping_address.city',
                'shipping_address.postal_code',
                'shipping_address.country',
            ]);

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function validation_messages_use_plain_field_names(): void
    {
        $this->physicalCart();

        $this->submit(['shipping_address' => ['name' => '', 'line1' => '', 'postal_code' => '']])
            ->assertSessionHasErrors();

        $errors = session('errors');

        $this->assertSame('The name field is required.', $errors->first('shipping_address.name'));
        $this->assertSame('The address field is required.', $errors->first('shipping_address.line1'));
        $this->assertSame('The postal code field is required.', $errors->first('shipping_address.postal_code'));
        $this->assertStringNotContainsString('shipping_address', implode(' ', $errors->all()));
    }

    #[Test]
    public function postal_codes_are_required_only_where_countries_use_them(): void
    {
        $this->physicalCart();

        $this->submit(['shipping_address' => ['country' => 'GB', 'postal_code' => '']])
            ->assertSessionHasErrors('shipping_address.postal_code');

        // Hong Kong has no postal codes.
        $this->submit(['shipping_address' => ['country' => 'HK', 'postal_code' => '', 'city' => 'Kowloon']])
            ->assertSessionHasNoErrors();

        $this->assertSame('HK', Order::sole()->shipping_address['country']);
        $this->assertNull(Order::sole()->shipping_address['postal_code']);
    }

    #[Test]
    public function country_codes_are_accepted_in_any_case_and_stored_in_capitals(): void
    {
        $this->physicalCart();

        $this->submit(['shipping_address' => ['country' => 'gb']])->assertSessionHasNoErrors();

        $this->assertSame('GB', Order::sole()->shipping_address['country']);
    }

    #[Test]
    public function an_order_cannot_go_to_a_country_the_shop_does_not_serve(): void
    {
        $this->ukShopWithVat();
        $this->physicalCart();

        $this->submit(['shipping_address' => ['country' => 'JP', 'postal_code' => '100-0001']])
            ->assertSessionHasErrors('shipping_address.country');

        $this->assertStringContainsString("can't ship", session('errors')->first('shipping_address.country'));
        $this->assertSame(0, Order::count());
        $this->assertSame([], $this->stripe->created);
    }

    #[Test]
    public function a_shipping_method_from_another_zone_is_refused_and_the_shopper_stays_on_the_form(): void
    {
        $this->ukShopWithVat();
        $foreign = ShippingZone::factory()->serving(['DE'])->withRate('Elsewhere', '0.01')->create()->rates()->firstOrFail();
        $this->physicalCart();

        $response = $this->submit(['shipping_rate_id' => $foreign->id]);

        $response->assertSessionHasErrors('shipping_rate_id');
        $response->assertRedirect();
        $this->assertSame(0, Order::count(), 'a tampered rate id cannot buy cheaper shipping');
    }

    #[Test]
    public function the_chosen_shipping_method_sets_the_price(): void
    {
        $zone = ShippingZone::factory()->serving(['GB'])->create();
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Standard', 'amount' => '5.00', 'position' => 1]);
        $express = ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Express', 'amount' => '15.00', 'position' => 2]);
        $this->physicalCart('10.00', 1);

        $this->submit(['shipping_rate_id' => $express->id])->assertRedirect();

        $order = Order::sole();
        $this->assertSame('15.00', $order->shipping_total);
        $this->assertSame('Express', $order->shipping_method);
        $this->assertSame('25.00', $order->grand_total);
        $this->assertSame($express->id, $order->metadata['shipping_rate_id']);
    }

    #[Test]
    public function the_state_is_required_where_tax_depends_on_it(): void
    {
        TaxRate::factory()->create(['name' => 'CA tax', 'country' => 'US', 'region' => 'California', 'rate' => '7.25']);
        $this->physicalCart('100.00', 1);
        $us = ['country' => 'US', 'postal_code' => '94105', 'city' => 'San Francisco'];

        $response = $this->submit(['shipping_address' => $us]);
        $response->assertSessionHasErrors('shipping_address.region');
        $this->assertSame(0, Order::count());

        $this->submit(['shipping_address' => $us + ['region' => 'California']])->assertSessionHasNoErrors();

        $this->assertSame('7.25', Order::sole()->tax_total);
    }

    #[Test]
    public function a_separate_billing_address_is_required_when_the_shopper_says_it_differs(): void
    {
        $this->physicalCart();

        $this->submit(['billing_same_as_shipping' => false])->assertSessionHasErrors('billing_address');

        $this->submit([
            'billing_same_as_shipping' => false,
            'billing_address' => $this->addressFields(['name' => 'Accounts Dept', 'line1' => '9 Ledger Lane', 'country' => 'IE', 'postal_code' => 'D01 F5P2']),
        ])->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('IE', $order->billing_address['country']);
        $this->assertSame('Accounts Dept', $order->billing_address['name']);
        $this->assertSame('GB', $order->shipping_address['country']);
    }

    #[Test]
    public function downloads_need_no_address_when_tax_does_not_depend_on_location(): void
    {
        $product = Product::factory()->digital()->priced('20.00')->create();
        $this->cartWith($product);

        $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'token' => (string) Str::uuid()])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $order = Order::sole();
        $this->assertNull($order->shipping_address);
        $this->assertNull($order->billing_address);
        $this->assertSame('0.00', $order->shipping_total);
        $this->assertNull($order->shipping_method);
    }

    #[Test]
    public function downloads_need_a_billing_address_when_the_shop_charges_tax_by_location(): void
    {
        TaxRate::factory()->create(['name' => 'MwSt', 'country' => 'DE', 'rate' => '19']);
        $this->cartWith(Product::factory()->digital()->priced('100.00')->create());
        $payload = ['email' => 'buyer@example.com', 'token' => (string) Str::uuid()];

        $this->post(route('shop.checkout.store'), $payload)->assertSessionHasErrors('billing_address');

        $this->post(route('shop.checkout.store'), $payload + [
            'billing_address' => $this->addressFields(['country' => 'DE', 'postal_code' => '10115', 'city' => 'Berlin']),
        ])->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame('19.00', $order->tax_total);
        $this->assertSame('119.00', $order->grand_total);
        $this->assertNull($order->shipping_address, 'nothing is shipped');
        $this->assertSame('DE', $order->billing_address['country']);
    }

    #[Test]
    public function shipping_and_tax_cannot_be_dodged_by_posting_nothing_for_them(): void
    {
        $this->ukShopWithVat();
        $this->physicalCart('50.00', 2);

        // The browser never sends totals, but make sure extra fields cannot set them either.
        $this->submit(['shipping_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '1.00'])->assertRedirect();

        $this->assertSame('126.00', Order::sole()->grand_total);
    }

    /*
    |--------------------------------------------------------------------------
    | The address book
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_signed_in_customer_can_ship_to_a_saved_address(): void
    {
        $this->ukShopWithVat();
        $user = User::factory()->create();
        $saved = Address::factory()->forOwner($user)->inCountry('GB')->create(['name' => 'Home', 'line1' => '7 Saved Street']);
        $this->cartWith(Product::factory()->priced('10.00')->stocked(5)->create(), 1, $user);

        $this->actingAs($user)
            ->post(route('shop.checkout.store'), ['token' => (string) Str::uuid(), 'shipping_address_id' => $saved->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $order = Order::sole();
        $this->assertSame('7 Saved Street', $order->shipping_address['line1']);
        $this->assertSame('Home', $order->shipping_address['name']);
        $this->assertSame('3.00', $order->tax_total, '20% of the 10.00 item (2.00) plus 20% of the 5.00 shipping (1.00)');
    }

    #[Test]
    public function an_order_keeps_its_own_copy_of_the_address(): void
    {
        $user = User::factory()->create();
        $saved = Address::factory()->forOwner($user)->create(['line1' => 'Original Road']);
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);
        $this->actingAs($user)->post(route('shop.checkout.store'), ['token' => (string) Str::uuid(), 'shipping_address_id' => $saved->id]);

        $saved->update(['line1' => 'Changed Road']);

        $this->assertSame('Original Road', Order::sole()->shipping_address['line1']);
    }

    #[Test]
    public function someone_elses_saved_address_cannot_be_used(): void
    {
        $owner = User::factory()->create();
        $theirs = Address::factory()->forOwner($owner)->create();
        $user = User::factory()->create();
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);

        $this->actingAs($user)
            ->post(route('shop.checkout.store'), ['token' => (string) Str::uuid(), 'shipping_address_id' => $theirs->id])
            ->assertSessionHasErrors('shipping_address_id');

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function a_guest_cannot_use_a_saved_address_id(): void
    {
        $saved = Address::factory()->create();
        $this->physicalCart();

        $this->post(route('shop.checkout.store'), ['email' => 'a@example.com', 'token' => (string) Str::uuid(), 'shipping_address_id' => $saved->id])
            ->assertSessionHasErrors('shipping_address_id');

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function a_typed_address_is_saved_on_request_without_duplicates_and_the_first_becomes_the_default(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->priced('10.00')->stocked(10)->create();
        $this->cartWith($product, 1, $user);

        $this->actingAs($user)->post(route('shop.checkout.store'), $this->checkoutPayload(['save_addresses' => true]))->assertRedirect();

        $saved = Address::query()->visibleTo($user)->get();
        $this->assertCount(1, $saved);
        $this->assertTrue($saved->first()->is_default);
        $this->assertSame('1 Test Street', $saved->first()->line1);

        // Checking out again with the same address does not add a second copy.
        $this->actingAs($user)->post(route('shop.checkout.store'), $this->checkoutPayload(['save_addresses' => true]))->assertRedirect();

        $this->assertSame(1, Address::query()->visibleTo($user)->count());
    }

    #[Test]
    public function an_address_is_not_saved_unless_asked(): void
    {
        $user = User::factory()->create();
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);

        $this->actingAs($user)->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $this->assertSame(0, Address::count());
    }

    #[Test]
    public function an_address_is_not_saved_when_checkout_fails(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->priced('10.00')->stocked(0)->create();
        $this->cartWith($product, 1, $user);

        $this->actingAs($user)->post(route('shop.checkout.store'), $this->checkoutPayload(['save_addresses' => true]))->assertSessionHas('error');

        $this->assertSame(0, Address::count());
    }

    /*
    |--------------------------------------------------------------------------
    | What the customer sees
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_confirmation_page_shows_where_it_ships_and_the_tax(): void
    {
        $this->ukShopWithVat();
        $this->physicalCart('50.00', 2);
        $this->submit();
        $order = Order::sole();

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.shipping_method', 'Standard')
                ->where('order.shipping_total', '5.00')
                ->where('order.tax_total', '21.00')
                ->where('order.tax_lines.0.name', 'VAT')
                ->where('order.shipping_address.line1', '1 Test Street')
                ->where('order.grand_total', '126.00'));
    }

    #[Test]
    public function the_receipt_email_lists_shipping_tax_and_the_destination(): void
    {
        Notification::fake();
        $this->ukShopWithVat();
        $this->physicalCart('50.00', 2);
        $this->submit();
        $order = Order::sole();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->firstOrFail())))->assertOk();

        Notification::assertSentOnDemand(OrderConfirmation::class, function (OrderConfirmation $notification) {
            $lines = implode("\n", $notification->toMail((object) [])->introLines);

            $this->assertStringContainsString('Shipping (Standard): 5.00 USD', $lines);
            $this->assertStringContainsString('VAT (20%): 21.00 USD', $lines);
            $this->assertStringContainsString('Shipping to: Ada Buyer, 1 Test Street, London, N1 1AA, United Kingdom', $lines);
            $this->assertStringContainsString('Total paid: 126.00 USD', $lines);

            return true;
        });
    }
}
