<?php

namespace Tests\Feature\Commerce;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * The checkout page recalculates shipping and tax as the shopper fills in their
 * address. Only country, region and the chosen method are sent.
 */
class CheckoutQuoteTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();

        $zone = ShippingZone::factory()->serving(['GB', 'IE'])->create();
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Standard', 'amount' => '5.00', 'position' => 1]);
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Express', 'amount' => '15.00', 'position' => 2]);
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20']);
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1);
    }

    #[Test]
    public function the_page_describes_what_the_cart_needs(): void
    {
        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('needsShipping', true)
            ->where('billingRequired', false)
            ->where('countries', ['GB', 'IE'])
            ->where('quote.needs_shipping', true)
            ->where('quote.grand_total', '10.00'));
    }

    #[Test]
    public function the_quote_prices_shipping_and_tax_for_a_country(): void
    {
        $this->get(route('shop.checkout', ['ship_country' => 'GB']))->assertInertia(fn (Assert $page) => $page
            ->where('quote.error', null)
            ->where('quote.shipping.can_ship', true)
            ->where('quote.shipping.selected_id', fn ($id) => $id > 0)
            ->where('quote.shipping.amount', '5.00')
            ->has('quote.shipping.options', 2)
            ->where('quote.tax.lines.0.name', 'VAT')
            ->where('quote.tax.total', '3.00')
            ->where('quote.grand_total', '18.00'));
    }

    #[Test]
    public function the_chosen_method_changes_the_quote(): void
    {
        $express = ShippingRate::where('name', 'Express')->firstOrFail();

        $this->get(route('shop.checkout', ['ship_country' => 'GB', 'rate' => $express->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('quote.shipping.selected_id', $express->id)
                ->where('quote.shipping.amount', '15.00')
                ->where('quote.tax.total', '5.00')
                ->where('quote.grand_total', '30.00'));
    }

    #[Test]
    public function a_country_without_tax_pays_none(): void
    {
        $this->get(route('shop.checkout', ['ship_country' => 'IE']))->assertInertia(fn (Assert $page) => $page
            ->where('quote.tax.lines', [])
            ->where('quote.tax.total', '0.00')
            ->where('quote.grand_total', '15.00'));
    }

    #[Test]
    public function a_country_that_is_not_served_cannot_ship(): void
    {
        $this->get(route('shop.checkout', ['ship_country' => 'JP']))->assertInertia(fn (Assert $page) => $page
            ->where('quote.shipping.can_ship', false)
            ->where('quote.shipping.options', [])
            ->where('quote.shipping.message', fn ($message) => str_contains($message, 'Japan')));
    }

    #[Test]
    public function nonsense_in_the_query_is_ignored_not_trusted(): void
    {
        $this->get(route('shop.checkout', ['ship_country' => 'NOPE', 'rate' => 'abc', 'bill_country' => '??']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('quote.error', null)
                ->where('quote.shipping.options', [])
                ->where('quote.grand_total', '10.00'));
    }

    #[Test]
    public function the_quote_reports_when_the_state_is_needed(): void
    {
        TaxRate::factory()->create(['name' => 'CA tax', 'country' => 'US', 'region' => 'California']);
        ShippingZone::factory()->everywhereElse()->withRate('International', '20.00')->create();

        $this->get(route('shop.checkout', ['ship_country' => 'US']))->assertInertia(fn (Assert $page) => $page
            ->where('quote.tax.region_required', true));
        $this->get(route('shop.checkout', ['ship_country' => 'GB']))->assertInertia(fn (Assert $page) => $page
            ->where('quote.tax.region_required', false));
    }

    #[Test]
    public function a_cart_with_an_unavailable_item_reports_it_instead_of_failing(): void
    {
        Product::query()->update(['is_active' => false]);

        $this->get(route('shop.checkout', ['ship_country' => 'GB']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('quote.error', fn ($error) => str_contains($error, 'no longer available')));
    }

    #[Test]
    public function a_partial_reload_returns_just_the_quote(): void
    {
        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
            'X-Inertia-Partial-Component' => 'commerce/Checkout',
            'X-Inertia-Partial-Data' => 'quote',
        ])->get(route('shop.checkout', ['ship_country' => 'GB']));

        $props = $response->json('props');

        $this->assertSame('18.00', $props['quote']['grand_total']);
        $this->assertArrayNotHasKey('token', $props, 'the form token is not regenerated while the shopper types');
        $this->assertArrayNotHasKey('addresses', $props);
    }

    #[Test]
    public function a_signed_in_customer_sees_their_saved_addresses(): void
    {
        $user = User::factory()->create();
        $default = Address::factory()->forOwner($user)->default()->create(['name' => 'Home']);
        Address::factory()->forOwner(User::factory()->create())->create(['name' => 'Not mine']);
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);

        $this->actingAs($user)->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->has('addresses', 1)
            ->where('addresses.0.id', $default->id)
            ->where('addresses.0.name', 'Home')
            ->where('addresses.0.is_default', true));
    }

    #[Test]
    public function the_default_saved_address_is_priced_on_the_first_visit(): void
    {
        $user = User::factory()->create();
        Address::factory()->forOwner($user)->inCountry('IE')->create();
        Address::factory()->forOwner($user)->inCountry('GB')->default()->create();
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);

        // No query at all: the page pre-selects the default address, so the quote already reflects it.
        $this->actingAs($user)->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('addresses.0.country', 'GB')
            ->where('quote.shipping.amount', '5.00')
            ->where('quote.tax.total', '3.00')
            ->where('quote.grand_total', '18.00'));
    }

    #[Test]
    public function with_no_default_the_oldest_saved_address_is_the_one_pre_selected(): void
    {
        $user = User::factory()->create();
        Address::factory()->forOwner($user)->inCountry('IE')->create();
        Address::factory()->forOwner($user)->inCountry('GB')->create();
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);

        $this->actingAs($user)->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('addresses.0.country', 'IE')
            ->where('quote.tax.total', '0.00', 'Ireland has no tax rate in this setup')
            ->where('quote.grand_total', '15.00'));
    }

    #[Test]
    public function naming_an_empty_country_means_a_new_address_is_being_typed_not_a_first_visit(): void
    {
        $user = User::factory()->create();
        Address::factory()->forOwner($user)->inCountry('GB')->default()->create();
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);

        $this->actingAs($user)->get(route('shop.checkout', ['ship_country' => '']))->assertInertia(fn (Assert $page) => $page
            ->where('quote.shipping.options', [])
            ->where('quote.grand_total', '10.00'));
    }

    #[Test]
    public function an_explicit_country_overrides_the_pre_selected_saved_address(): void
    {
        $user = User::factory()->create();
        Address::factory()->forOwner($user)->inCountry('GB')->default()->create();
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);

        $this->actingAs($user)->get(route('shop.checkout', ['ship_country' => 'IE']))->assertInertia(fn (Assert $page) => $page
            ->where('quote.tax.total', '0.00')
            ->where('quote.grand_total', '15.00'));
    }

    #[Test]
    public function a_guest_is_never_priced_for_an_address_they_have_not_entered(): void
    {
        Address::factory()->inCountry('GB')->default()->create();

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('quote.shipping.options', [])
            ->where('quote.grand_total', '10.00'));
    }

    #[Test]
    public function someone_elses_address_is_never_used_for_the_quote(): void
    {
        $user = User::factory()->create();
        Address::factory()->forOwner(User::factory()->create())->inCountry('GB')->default()->create();
        $this->cartWith(Product::factory()->priced('10.00')->create(), 1, $user);

        $this->actingAs($user)->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('addresses', [])
            ->where('quote.grand_total', '10.00'));
    }

    #[Test]
    public function a_guest_has_no_saved_addresses(): void
    {
        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page->where('addresses', []));
    }

    #[Test]
    public function a_digital_only_cart_lists_every_country_and_asks_for_billing_when_tax_depends_on_location(): void
    {
        Cart::query()->delete();
        $this->cartWith(Product::factory()->digital()->priced('10.00')->create(), 1);

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('needsShipping', false)
            ->where('billingRequired', true)
            ->where('countries', fn ($countries) => count($countries) === 249));
    }
}
