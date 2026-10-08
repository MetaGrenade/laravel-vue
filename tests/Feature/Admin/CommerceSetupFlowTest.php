<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * The whole loop: a merchant sets up shipping and tax in the admin area, and a
 * customer's checkout reflects it straight away.
 */
class CommerceSetupFlowTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * actingAs() stays in force for later requests, so sign the admin out before acting as a shopper.
     */
    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    #[Test]
    public function what_the_merchant_sets_up_is_what_the_customer_is_charged(): void
    {
        $admin = $this->admin();

        // 1. The merchant sets up a UK zone with two rates, and VAT.
        $this->actingAs($admin)->post(route('acp.commerce.shipping.zones.store'), ['name' => 'United Kingdom', 'countries' => ['gb']])->assertSessionHasNoErrors();
        $zone = ShippingZone::sole();
        $this->actingAs($admin)->post(route('acp.commerce.shipping.rates.store', $zone), ['name' => 'Standard', 'amount' => '5'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('acp.commerce.shipping.rates.store', $zone), ['name' => 'Free over 100', 'amount' => '0', 'min_subtotal' => '100'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('acp.commerce.tax-rates.store'), ['name' => 'VAT', 'country' => 'GB', 'rate' => '20'])->assertSessionHasNoErrors();

        // 2. A guest with a 50.00 cart sees Standard shipping and VAT on items and shipping.
        $product = Product::factory()->priced('50.00')->stocked(5)->create();
        $this->cartWith($product, 1);

        $this->asGuest()->get(route('shop.checkout', ['ship_country' => 'GB']))->assertInertia(fn (Assert $page) => $page
            ->where('countries', ['GB'])
            ->where('quote.shipping.options.0.name', 'Standard')
            ->where('quote.shipping.amount', '5.00')
            ->where('quote.tax.lines.0.name', 'VAT')
            ->where('quote.tax.total', '11.00')
            ->where('quote.grand_total', '66.00'));

        // 3. And what they pay is what the order records.
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::sole();
        $this->assertSame('66.00', $order->grand_total);
        $this->assertSame('Standard', $order->shipping_method);
        $this->assertSame('11.00', $order->tax_total);
    }

    #[Test]
    public function changing_a_rate_changes_the_next_quote(): void
    {
        $admin = $this->admin();
        $zone = ShippingZone::factory()->serving(['GB'])->create();
        $rate = ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Standard', 'amount' => '5.00']);
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1);

        $this->get(route('shop.checkout', ['ship_country' => 'GB']))->assertInertia(fn (Assert $page) => $page->where('quote.grand_total', '15.00'));

        $this->actingAs($admin)->put(route('acp.commerce.shipping.rates.update', $rate), ['name' => 'Standard', 'amount' => '7.50'])->assertSessionHasNoErrors();

        $this->asGuest()->get(route('shop.checkout', ['ship_country' => 'GB']))->assertInertia(fn (Assert $page) => $page->where('quote.grand_total', '17.50'));
    }

    #[Test]
    public function an_order_keeps_what_it_was_charged_when_the_merchant_later_changes_or_deletes_the_rate(): void
    {
        $admin = $this->admin();
        $zone = ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
        TaxRate::factory()->create(['country' => 'GB', 'rate' => '20']);
        $this->cartWith(Product::factory()->priced('10.00')->stocked(5)->create(), 1);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::sole();
        $this->assertSame('18.00', $order->grand_total, '10.00 of items, 5.00 shipping and 3.00 tax');

        $this->actingAs($admin)->delete(route('acp.commerce.shipping.zones.destroy', $zone))->assertSessionHas('success');
        $this->actingAs($admin)->delete(route('acp.commerce.tax-rates.destroy', TaxRate::sole()))->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('5.00', $order->shipping_total);
        $this->assertSame('Standard', $order->shipping_method);
        $this->assertSame('3.00', $order->tax_total, '20% of the 10.00 item and of the 5.00 shipping');
        $this->assertSame('18.00', $order->grand_total);
    }

    #[Test]
    public function deleting_the_only_zone_returns_to_free_shipping_anywhere(): void
    {
        $admin = $this->admin();
        $zone = ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1);

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page->where('countries', ['GB']));

        $this->actingAs($admin)->delete(route('acp.commerce.shipping.zones.destroy', $zone));

        $this->asGuest()->get(route('shop.checkout', ['ship_country' => 'JP']))->assertInertia(fn (Assert $page) => $page
            ->where('countries', fn ($countries) => collect($countries)->count() === 249)
            ->where('quote.shipping.can_ship', true)
            ->where('quote.grand_total', '10.00'));
    }

    #[Test]
    public function a_pending_checkout_that_chose_a_rate_which_was_since_deleted_is_asked_to_choose_again(): void
    {
        $admin = $this->admin();
        $zone = ShippingZone::factory()->serving(['GB'])->create();
        $standard = ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Standard', 'amount' => '5.00', 'position' => 1]);
        $express = ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Express', 'amount' => '15.00', 'position' => 2]);
        $this->cartWith(Product::factory()->priced('10.00')->stocked(5)->create(), 1);

        // The customer had Express selected, then the merchant removes it before they pay.
        $this->actingAs($admin)->delete(route('acp.commerce.shipping.rates.destroy', $express));

        $this->asGuest()->post(route('shop.checkout.store'), $this->checkoutPayload(['shipping_rate_id' => $express->id]))
            ->assertSessionHasErrors('shipping_rate_id');

        $this->assertSame(0, Order::count());
        $this->assertNotNull($standard->fresh());
    }
}
