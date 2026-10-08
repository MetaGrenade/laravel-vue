<?php

namespace Tests\Feature\Commerce;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * What the shop shows and lets a shopper add must be what checkout will accept: a product that has
 * variants is only sold as one of them, and only a price that can be charged is ever shown.
 */
class StorefrontAvailabilityTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function listed(): array
    {
        $page = $this->get(route('shop.index'))->viewData('page')['props']['products']['data'];

        return collect($page)->keyBy('name')->all();
    }

    // --- A product with variants is only sold as one of them --------------------------------------

    #[Test]
    public function a_product_with_variants_cannot_be_added_without_choosing_one(): void
    {
        $product = Product::factory()->priced('10.00')->create();
        ProductVariant::factory()->for($product)->stocked(5)->create();

        $this->post(route('shop.cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHas('error', 'Choose an option for this product.');

        $this->assertSame(0, CartItem::count());
    }

    #[Test]
    public function a_product_whose_variants_are_all_off_cannot_be_bought_as_the_base_product(): void
    {
        // The storefront sends no variant at all for it, because none is on offer. It must not fall
        // back to the product's own price and skip the variants' stock.
        $product = Product::factory()->priced('10.00')->create();
        ProductVariant::factory()->for($product)->stocked(0)->create(['is_active' => false]);

        $this->post(route('shop.cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHas('error', 'This product is not available.');

        $this->assertSame(0, CartItem::count());
    }

    #[Test]
    public function a_product_without_variants_is_still_added_as_it_is(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(5)->create();

        $this->post(route('shop.cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHas('success');

        $this->assertSame(1, CartItem::count());
    }

    #[Test]
    public function a_cart_line_for_the_base_product_stops_checkout_once_the_product_has_variants(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(5)->create();
        $this->cartWith($product);
        ProductVariant::factory()->for($product)->priced('12.00')->stocked(5)->create();

        $this->post(route('shop.checkout.store'), $this->checkoutPayload())
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'no longer available in the option you chose'));

        $this->assertSame(0, Order::count());
    }

    // --- The storefront shows what can be charged -------------------------------------------------

    #[Test]
    public function only_the_price_that_will_be_charged_is_sent_to_the_storefront(): void
    {
        $product = Product::factory()->create(['name' => 'Hoodie']);
        // An old price that was switched off, a euro price, and the one in use.
        Price::factory()->for($product, 'priceable')->create(['amount' => '8.00', 'is_active' => false]);
        Price::factory()->for($product, 'priceable')->create(['amount' => '9.00', 'currency' => 'EUR']);
        Price::factory()->for($product, 'priceable')->create(['amount' => '12.00']);

        $prices = $this->listed()['Hoodie']['prices'];

        $this->assertCount(1, $prices);
        $this->assertSame('12.00', $prices[0]['amount']);
        $this->assertSame('USD', $prices[0]['currency']);
    }

    #[Test]
    public function the_price_shown_is_the_price_checkout_charges(): void
    {
        $product = Product::factory()->stocked(5)->create(['name' => 'Hoodie']);
        Price::factory()->for($product, 'priceable')->create(['amount' => '8.00', 'is_active' => false]);
        Price::factory()->for($product, 'priceable')->create(['amount' => '12.00']);
        $shown = $this->listed()['Hoodie']['prices'][0]['amount'];

        $this->cartWith($product);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $this->assertSame($shown, Order::sole()->subtotal);
        $this->assertSame('12.00', $shown);
    }

    #[Test]
    public function variant_prices_are_limited_the_same_way(): void
    {
        $product = Product::factory()->create(['name' => 'Tee']);
        $variant = ProductVariant::factory()->for($product)->create(['name' => 'M']);
        Price::factory()->for($variant, 'priceable')->create(['amount' => '5.00', 'is_active' => false]);
        Price::factory()->for($variant, 'priceable')->create(['amount' => '7.00', 'currency' => 'EUR']);
        Price::factory()->for($variant, 'priceable')->create(['amount' => '15.00']);

        $prices = $this->listed()['Tee']['variants'][0]['prices'];

        $this->assertSame(['15.00'], array_column($prices, 'amount'));
    }

    #[Test]
    public function the_product_page_sends_the_same_limited_prices(): void
    {
        $product = Product::factory()->create();
        Price::factory()->for($product, 'priceable')->create(['amount' => '8.00', 'is_active' => false]);
        Price::factory()->for($product, 'priceable')->create(['amount' => '12.00']);

        $this->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page
            ->has('product.prices', 1)
            ->where('product.prices.0.amount', '12.00')
            ->where('product.can_buy', true));
    }

    #[Test]
    public function the_cheapest_charged_price_comes_first_as_checkout_picks_it(): void
    {
        $product = Product::factory()->create(['name' => 'Hoodie']);
        Price::factory()->for($product, 'priceable')->create(['amount' => '20.00']);
        Price::factory()->for($product, 'priceable')->create(['amount' => '15.00']);

        $this->assertSame(['15.00', '20.00'], array_column($this->listed()['Hoodie']['prices'], 'amount'));
    }

    // --- Whether it can be bought -----------------------------------------------------------------

    #[Test]
    public function the_storefront_says_what_can_be_bought(): void
    {
        Product::factory()->priced('10.00')->create(['name' => 'Plain']);
        Product::factory()->priced('10.00', 'EUR')->create(['name' => 'Euro only']);
        Product::factory()->create(['name' => 'Unpriced']);

        $allOff = Product::factory()->priced('10.00')->create(['name' => 'All off']);
        ProductVariant::factory()->for($allOff)->create(['is_active' => false]);

        $fallback = Product::factory()->priced('10.00')->create(['name' => 'Falls back']);
        ProductVariant::factory()->for($fallback)->create(); // No price of its own: sold at the product's.

        $own = Product::factory()->create(['name' => 'Variant priced']);
        ProductVariant::factory()->for($own)->priced('9.00')->create();

        $none = Product::factory()->create(['name' => 'Nothing priced']);
        ProductVariant::factory()->for($none)->create();

        $mixed = Product::factory()->create(['name' => 'One variant priced']);
        ProductVariant::factory()->for($mixed)->create(['name' => 'Unpriced']);
        ProductVariant::factory()->for($mixed)->priced('9.00')->create(['name' => 'Priced']);

        $listed = $this->listed();

        $this->assertEquals(
            [
                'Plain' => true,
                'Euro only' => false,
                'Unpriced' => false,
                'All off' => false,
                'Falls back' => true,
                'Variant priced' => true,
                'Nothing priced' => false,
                'One variant priced' => true,
            ],
            collect($listed)->map(fn ($product) => $product['can_buy'])->all(),
        );
    }

    #[Test]
    public function an_archived_product_is_not_listed_at_all(): void
    {
        Product::factory()->priced('10.00')->inactive()->create(['name' => 'Old']);

        $this->assertArrayNotHasKey('Old', $this->listed());
    }

    #[Test]
    public function the_product_page_says_when_it_cannot_be_bought(): void
    {
        $product = Product::factory()->priced('10.00')->create();
        ProductVariant::factory()->for($product)->create(['is_active' => false]);

        $this->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page->where('product.can_buy', false));
    }

    #[Test]
    public function every_product_the_storefront_offers_can_actually_be_added(): void
    {
        // The rule the button follows and the rule the cart enforces agree, whichever way a
        // product is set up.
        $cases = [];

        $cases['plain'] = Product::factory()->priced('10.00')->create();

        $cases['variants all off'] = Product::factory()->priced('10.00')->create();
        ProductVariant::factory()->for($cases['variants all off'])->create(['is_active' => false]);

        $cases['falls back'] = Product::factory()->priced('10.00')->create();
        ProductVariant::factory()->for($cases['falls back'])->create();

        $cases['euro only'] = Product::factory()->priced('10.00', 'EUR')->create();
        $cases['unpriced'] = Product::factory()->create();

        $listed = collect($this->get(route('shop.index'))->viewData('page')['props']['products']['data'])->keyBy('id');

        foreach ($cases as $label => $product) {
            $variant = $product->variants()->where('is_active', true)->first();

            $this->post(route('shop.cart.items.store'), array_filter([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => 1,
            ]));

            $added = CartItem::where('product_id', $product->id)->exists();

            $this->assertSame($listed[$product->id]['can_buy'], $added, "the button and the cart disagree for: {$label}");
        }

    }
}
