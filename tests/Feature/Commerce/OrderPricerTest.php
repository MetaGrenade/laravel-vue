<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutException;
use App\Support\Commerce\Destination;
use App\Support\Commerce\InvalidCheckoutInput;
use App\Support\Commerce\OrderPricer;
use App\Support\Commerce\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * The one place that decides what an order costs: items, shipping, tax and the
 * total. Used for the live quote (lenient) and for placing the order (strict).
 */
class OrderPricerTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    private function pricer(): OrderPricer
    {
        return app(OrderPricer::class);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $items
     */
    private function cartOf(array $items): Cart
    {
        $cart = Cart::create(['session_id' => 'pricer-test', 'currency' => 'USD']);

        foreach ($items as [$product, $quantity]) {
            CartManager::addItem($cart, $product, null, $product->prices()->firstOrFail(), $quantity);
        }

        return $cart->fresh();
    }

    private function uk(): Destination
    {
        return new Destination('GB');
    }

    private function ukZone(): ShippingZone
    {
        return ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();
    }

    private function price(Cart $cart, ?Destination $ship = null, ?Destination $bill = null, ?int $rate = null, bool $strict = false): Pricing
    {
        return $this->pricer()->price($cart, $ship, $bill, $rate, $strict);
    }

    #[Test]
    public function totals_add_up_items_shipping_and_tax(): void
    {
        $this->ukZone();
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20']);
        $cart = $this->cartOf([[Product::factory()->priced('50.00')->create(), 2]]);

        $pricing = $this->price($cart, $this->uk());

        $this->assertSame('100.00', $pricing->subtotal->toDecimal());
        $this->assertSame('5.00', $pricing->shippingTotal()->toDecimal());
        $this->assertSame('21.00', $pricing->taxTotal()->toDecimal(), '20% of items and of shipping');
        $this->assertSame('126.00', $pricing->grandTotal->toDecimal());
        $this->assertSame('Standard', $pricing->shipping?->name);
    }

    #[Test]
    public function the_quote_array_is_what_the_checkout_page_shows(): void
    {
        $this->ukZone();
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20']);
        $cart = $this->cartOf([[Product::factory()->priced('10.00')->create(), 1]]);

        $quote = $this->price($cart, $this->uk())->toArray();

        $this->assertSame('USD', $quote['currency']);
        $this->assertTrue($quote['needs_shipping']);
        $this->assertTrue($quote['shipping']['can_ship']);
        $this->assertSame('5.00', $quote['shipping']['amount']);
        $this->assertSame('Standard', $quote['shipping']['options'][0]['name']);
        $this->assertSame(['name' => 'VAT', 'rate' => '20', 'amount' => '3.00'], $quote['tax']['lines'][0]);
        $this->assertSame('18.00', $quote['grand_total']);
    }

    #[Test]
    public function without_an_address_a_quote_asks_for_one_instead_of_failing(): void
    {
        $this->ukZone();
        $cart = $this->cartOf([[Product::factory()->priced('10.00')->create(), 1]]);

        $pricing = $this->price($cart);

        $this->assertTrue($pricing->needsShipping);
        $this->assertSame([], $pricing->shippingOptions);
        $this->assertStringContainsString('shipping address', (string) $pricing->shippingMessage);
        $this->assertSame('10.00', $pricing->grandTotal->toDecimal(), 'nothing is guessed before the address is known');
    }

    #[Test]
    public function placing_an_order_without_a_shipping_address_is_refused(): void
    {
        $cart = $this->cartOf([[Product::factory()->priced('10.00')->create(), 1]]);

        try {
            $this->price($cart, strict: true);
            $this->fail('expected the order to be refused');
        } catch (InvalidCheckoutInput $exception) {
            $this->assertSame('shipping_address', $exception->field);
        }
    }

    #[Test]
    public function a_destination_nobody_ships_to_cannot_ship_and_a_strict_order_is_refused(): void
    {
        $this->ukZone();
        $cart = $this->cartOf([[Product::factory()->priced('10.00')->create(), 1]]);

        $quote = $this->price($cart, new Destination('JP'));
        $this->assertFalse($quote->canShip);
        $this->assertStringContainsString('Japan', (string) $quote->shippingMessage);

        try {
            $this->price($cart, new Destination('JP'), strict: true);
            $this->fail('expected the order to be refused');
        } catch (InvalidCheckoutInput $exception) {
            $this->assertSame('shipping_address.country', $exception->field);
        }
    }

    #[Test]
    public function with_no_zones_configured_any_country_ships_free(): void
    {
        $cart = $this->cartOf([[Product::factory()->priced('10.00')->create(), 1]]);

        $pricing = $this->price($cart, new Destination('JP'), strict: true);

        $this->assertTrue($pricing->canShip);
        $this->assertNull($pricing->shipping);
        $this->assertTrue($pricing->shippingTotal()->isZero());
        $this->assertSame('10.00', $pricing->grandTotal->toDecimal());
    }

    #[Test]
    public function the_chosen_shipping_method_is_used_and_the_first_is_the_default(): void
    {
        $zone = ShippingZone::factory()->serving(['GB'])->create();
        $standard = ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Standard', 'amount' => '5.00', 'position' => 1]);
        $express = ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Express', 'amount' => '15.00', 'position' => 2]);
        $cart = $this->cartOf([[Product::factory()->priced('10.00')->create(), 1]]);

        $this->assertSame($standard->id, $this->price($cart, $this->uk())->shipping?->id);
        $this->assertSame($express->id, $this->price($cart, $this->uk(), rate: $express->id)->shipping?->id);
        $this->assertSame('25.00', $this->price($cart, $this->uk(), rate: $express->id)->grandTotal->toDecimal());
    }

    #[Test]
    public function a_shipping_method_that_is_not_offered_is_replaced_in_a_quote_but_refused_when_placing(): void
    {
        $this->ukZone();
        $otherZone = ShippingZone::factory()->serving(['DE'])->withRate('Elsewhere', '99.00')->create();
        $foreign = $otherZone->rates()->firstOrFail();
        $cart = $this->cartOf([[Product::factory()->priced('10.00')->create(), 1]]);

        $this->assertSame('Standard', $this->price($cart, $this->uk(), rate: $foreign->id)->shipping?->name);

        try {
            $this->price($cart, $this->uk(), rate: $foreign->id, strict: true);
            $this->fail('a rate from another zone must not be accepted');
        } catch (InvalidCheckoutInput $exception) {
            $this->assertSame('shipping_rate_id', $exception->field);
        }
    }

    #[Test]
    public function only_shippable_items_count_towards_the_rate_limits(): void
    {
        $zone = ShippingZone::factory()->serving(['GB'])->create();
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Free over 50', 'amount' => '0.00', 'min_subtotal' => '50.00', 'position' => 1]);
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Standard', 'amount' => '5.00', 'position' => 2]);
        $physical = Product::factory()->priced('30.00')->create();
        $digital = Product::factory()->digital()->priced('100.00')->create();

        $pricing = $this->price($this->cartOf([[$physical, 1], [$digital, 1]]), $this->uk());

        $this->assertSame(['Standard'], collect($pricing->shippingOptions)->pluck('name')->all(), 'the download does not help the parcel qualify for free shipping');
    }

    #[Test]
    public function a_cart_of_downloads_needs_no_shipping_even_when_zones_exist(): void
    {
        $this->ukZone();
        $cart = $this->cartOf([[Product::factory()->digital()->priced('20.00')->create(), 1]]);

        $pricing = $this->price($cart, strict: true);

        $this->assertFalse($pricing->needsShipping);
        $this->assertNull($pricing->shipping);
        $this->assertSame('20.00', $pricing->grandTotal->toDecimal());
        $this->assertFalse($this->pricer()->cartNeedsShipping($cart));
    }

    #[Test]
    public function a_mixed_cart_ships_once(): void
    {
        $this->ukZone();
        $cart = $this->cartOf([
            [Product::factory()->priced('10.00')->create(), 1],
            [Product::factory()->priced('10.00')->create(), 1],
            [Product::factory()->digital()->priced('10.00')->create(), 1],
        ]);

        $pricing = $this->price($cart, $this->uk());

        $this->assertSame('5.00', $pricing->shippingTotal()->toDecimal(), 'one shipping charge, not one per item');
        $this->assertSame('35.00', $pricing->grandTotal->toDecimal());
    }

    #[Test]
    public function tax_on_goods_follows_the_shipping_country_not_the_billing_one(): void
    {
        $this->ukZone();
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20']);
        TaxRate::factory()->create(['name' => 'MwSt', 'country' => 'DE', 'rate' => '19']);
        $cart = $this->cartOf([[Product::factory()->priced('100.00')->create(), 1]]);

        $pricing = $this->price($cart, $this->uk(), new Destination('DE'));

        $this->assertSame(['VAT'], collect($pricing->taxLines)->pluck('name')->all());
    }

    #[Test]
    public function tax_on_downloads_follows_the_billing_country(): void
    {
        TaxRate::factory()->create(['name' => 'MwSt', 'country' => 'DE', 'rate' => '19']);
        $cart = $this->cartOf([[Product::factory()->digital()->priced('100.00')->create(), 1]]);

        $this->assertSame('19.00', $this->price($cart, null, new Destination('DE'))->taxTotal()->toDecimal());
        $this->assertTrue($this->price($cart)->taxTotal()->isZero(), 'no billing country yet, no tax');
    }

    #[Test]
    public function untaxed_products_are_left_out_of_the_tax_base_but_shipping_is_still_taxed(): void
    {
        $this->ukZone();
        TaxRate::factory()->create(['country' => 'GB', 'rate' => '20']);
        $taxed = Product::factory()->priced('50.00')->create();
        $books = Product::factory()->untaxed()->priced('50.00')->create();

        $pricing = $this->price($this->cartOf([[$taxed, 1], [$books, 1]]), $this->uk());

        // 20% of 50.00 of items plus 20% of 5.00 shipping.
        $this->assertSame('11.00', $pricing->taxTotal()->toDecimal());
        $this->assertSame('10.00', $pricing->itemTax->toDecimal());
        $this->assertSame('1.00', $pricing->shippingTax->toDecimal());
    }

    #[Test]
    public function item_tax_is_shared_between_taxable_lines_and_adds_up_exactly(): void
    {
        TaxRate::factory()->create(['country' => 'GB', 'rate' => '20']);
        $a = Product::factory()->priced('0.33')->create();
        $b = Product::factory()->priced('0.33')->create();
        $c = Product::factory()->priced('0.34')->create();
        $untaxed = Product::factory()->untaxed()->priced('9.00')->create();

        $pricing = $this->price($this->cartOf([[$a, 1], [$b, 1], [$c, 1], [$untaxed, 1]]), $this->uk());

        $lineTax = array_map(fn ($line) => $line->tax->minor, $pricing->lines);

        $this->assertSame($pricing->itemTax->minor, array_sum($lineTax), 'the line amounts add up to the item tax');
        $this->assertSame(0, $lineTax[3], 'an untaxed line carries no tax');
        $this->assertSame(20, $pricing->itemTax->minor, '20% of 1.00, rounded once on the whole');
    }

    #[Test]
    public function a_region_is_required_when_tax_depends_on_it(): void
    {
        TaxRate::factory()->create(['name' => 'CA tax', 'country' => 'US', 'region' => 'California', 'rate' => '7.25']);
        $cart = $this->cartOf([[Product::factory()->priced('100.00')->create(), 1]]);

        $quote = $this->price($cart, new Destination('US'));
        $this->assertTrue($quote->regionRequired);
        $this->assertFalse($this->price($cart, new Destination('GB'))->regionRequired);

        try {
            $this->price($cart, new Destination('US'), strict: true);
            $this->fail('expected a missing region to be refused');
        } catch (InvalidCheckoutInput $exception) {
            $this->assertSame('shipping_address.region', $exception->field);
        }

        $this->assertSame('7.25', $this->price($cart, new Destination('US', 'California'), strict: true)->taxTotal()->toDecimal());
    }

    #[Test]
    public function the_missing_region_error_points_at_the_billing_address_for_downloads(): void
    {
        TaxRate::factory()->create(['country' => 'US', 'region' => 'California']);
        $cart = $this->cartOf([[Product::factory()->digital()->priced('10.00')->create(), 1]]);

        try {
            $this->price($cart, null, new Destination('US'), strict: true);
            $this->fail('expected a missing region to be refused');
        } catch (InvalidCheckoutInput $exception) {
            $this->assertSame('billing_address.region', $exception->field);
        }
    }

    #[Test]
    public function prices_always_come_from_the_catalogue_even_when_a_quote_is_stale(): void
    {
        $product = Product::factory()->priced('10.00')->create();
        $cart = $this->cartOf([[$product, 1]]);
        $product->prices()->update(['amount' => '12.00']);

        $this->assertSame('12.00', $this->price($cart)->subtotal->toDecimal());
    }

    #[Test]
    public function an_unavailable_item_stops_pricing(): void
    {
        $product = Product::factory()->priced('10.00')->create(['name' => 'Gone']);
        $cart = $this->cartOf([[$product, 1]]);
        $product->update(['is_active' => false]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Gone is no longer available');

        $this->price($cart);
    }

    #[Test]
    public function an_empty_cart_cannot_be_priced(): void
    {
        $this->expectException(CheckoutException::class);

        $this->price(Cart::create(['session_id' => 'empty', 'currency' => 'USD']));
    }

    #[Test]
    public function destinations_are_built_only_from_real_countries(): void
    {
        $this->assertNull(Destination::make('ZZ'));
        $this->assertNull(Destination::make(''));
        $this->assertNull(Destination::make(null));
        $this->assertSame('GB', Destination::make(' gb ')?->country);
        $this->assertNull(Destination::make('GB', '   ')?->region);
        $this->assertSame('Bavaria', Destination::make('DE', ' Bavaria ')?->region);
    }
}
