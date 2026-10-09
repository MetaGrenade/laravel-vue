<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use App\Support\Commerce\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * What the shop window shows: pictures, what is left in words (never in numbers), and structured
 * data for search engines.
 */
class StorefrontProductPageTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->setUpCommerce();
    }

    private function picture(Product $product, int $position = 0, ?string $alt = null): ProductImage
    {
        return ProductImage::factory()->create(['product_id' => $product->id, 'position' => $position, 'alt' => $alt]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shown(Product $product): array
    {
        return $this->get(route('shop.products.show', $product))->viewData('page')['props']['product'];
    }

    // --- Pictures ---------------------------------------------------------------------------------

    #[Test]
    public function the_catalogue_shows_each_products_main_picture(): void
    {
        $product = Product::factory()->priced('10.00')->create(['name' => 'Hoodie']);
        $this->picture($product, 1, 'Back');
        $main = $this->picture($product, 0, 'Front');
        Product::factory()->priced('10.00')->create(['name' => 'Mug']);

        $this->get(route('shop.index'))->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Hoodie')
            ->where('products.data.0.image.alt', 'Front')
            ->where('products.data.0.image.url', $main->url())
            ->where('products.data.0.image.medium', $main->mediumUrl())
            ->where('products.data.0.image.thumb', $main->thumbUrl())
            ->where('products.data.1.image', null));

    }

    #[Test]
    public function a_picture_without_a_description_is_described_by_the_product_name(): void
    {
        $product = Product::factory()->priced('10.00')->create(['name' => 'Hoodie']);
        $this->picture($product);

        $this->get(route('shop.index'))->assertInertia(fn (Assert $page) => $page->where('products.data.0.image.alt', 'Hoodie'));
    }

    #[Test]
    public function the_product_page_shows_every_picture_in_order(): void
    {
        $product = Product::factory()->priced('10.00')->create(['name' => 'Hoodie']);
        $second = $this->picture($product, 1, 'Back');
        $first = $this->picture($product, 0, 'Front');

        $this->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page
            ->has('product.images', 2)
            ->where('product.images.0.url', $first->url())
            ->where('product.images.0.alt', 'Front')
            ->where('product.images.0.width', 1200)
            ->where('product.images.1.url', $second->url()));
    }

    #[Test]
    public function the_main_picture_is_the_pages_share_image(): void
    {
        $product = Product::factory()->priced('10.00')->create(['name' => 'Hoodie', 'slug' => 'hoodie']);
        $main = $this->picture($product);

        $this->get(route('shop.products.show', $product))
            ->assertOk()
            ->assertSee('property="og:image" content="'.url($main->url()).'"', false);
    }

    // --- Stock in words ---------------------------------------------------------------------------

    #[Test]
    public function stock_is_described_in_words_not_numbers(): void
    {
        config(['commerce.low_stock_threshold' => 5]);
        $product = Product::factory()->priced('10.00')->create();
        ProductVariant::factory()->for($product)->stocked(100)->create(['name' => 'Plenty']);
        ProductVariant::factory()->for($product)->stocked(3)->create(['name' => 'Few']);
        ProductVariant::factory()->for($product)->stocked(0)->create(['name' => 'None']);
        ProductVariant::factory()->for($product)->stocked(0, allowBackorder: true)->create(['name' => 'Later']);
        ProductVariant::factory()->for($product)->create(['name' => 'Untracked']);

        $shown = collect($this->shown($product)['variants'])->pluck('stock', 'name')->all();

        $this->assertSame(['Plenty' => 'in_stock', 'Few' => 'low', 'None' => 'out', 'Later' => 'backorder', 'Untracked' => 'in_stock'], $shown);
    }

    #[Test]
    public function the_exact_count_is_never_sent_to_the_shop(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(37)->create();
        ProductVariant::factory()->for($product)->stocked(12)->create();

        $json = $this->get(route('shop.products.show', $product))->viewData('page')['props'];
        $encoded = json_encode($json);

        $this->assertStringNotContainsString('inventory', $encoded);
        $this->assertStringNotContainsString('"quantity"', $encoded);
        $this->assertStringNotContainsString('37', $encoded);

        $listing = json_encode($this->get(route('shop.index'))->viewData('page')['props']);
        $this->assertStringNotContainsString('"quantity"', $listing);
        $this->assertStringNotContainsString('allow_backorder', $listing);
    }

    #[Test]
    public function a_variant_without_its_own_stock_uses_the_products(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(0)->create();
        ProductVariant::factory()->for($product)->create(['name' => 'Shares']);
        ProductVariant::factory()->for($product)->stocked(50)->create(['name' => 'Own']);

        $shown = collect($this->shown($product)['variants'])->pluck('stock', 'name')->all();

        // The same rule an order follows when it takes stock.
        $this->assertSame(['Shares' => 'out', 'Own' => 'in_stock'], $shown);
    }

    #[Test]
    public function a_product_sold_as_it_is_carries_its_own_stock_status(): void
    {
        $this->assertSame('in_stock', $this->shown(Product::factory()->priced('10.00')->create())['stock']);
        $this->assertSame('low', $this->shown(Product::factory()->priced('10.00')->stocked(2)->create())['stock']);
        $this->assertSame('out', $this->shown(Product::factory()->priced('10.00')->stocked(0)->create())['stock']);
    }

    #[Test]
    public function the_catalogue_says_when_everything_is_sold_out(): void
    {
        Product::factory()->priced('10.00')->stocked(0)->create(['name' => 'A out']);
        Product::factory()->priced('10.00')->stocked(4)->create(['name' => 'B some']);

        $sold = Product::factory()->priced('10.00')->create(['name' => 'C variants out']);
        ProductVariant::factory()->for($sold)->stocked(0)->create();
        ProductVariant::factory()->for($sold)->stocked(0)->create();

        $some = Product::factory()->priced('10.00')->create(['name' => 'D one left']);
        ProductVariant::factory()->for($some)->stocked(0)->create();
        ProductVariant::factory()->for($some)->stocked(9)->create();

        $listed = collect($this->get(route('shop.index'))->viewData('page')['props']['products']['data'])->pluck('sold_out', 'name')->all();

        $this->assertSame(['A out' => true, 'B some' => false, 'C variants out' => true, 'D one left' => false], $listed);
    }

    // --- The page gives the shopper what they need to choose --------------------------------------

    #[Test]
    public function the_page_sends_options_and_the_variants_made_from_them(): void
    {
        $product = Product::factory()->priced('10.00')->create();
        $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Size', 'display_name' => 'Garment size', 'position' => 1]);
        foreach (['S', 'M'] as $position => $value) {
            $size->values()->create(['value' => $value, 'position' => $position]);
        }
        ProductOption::create(['product_id' => $product->id, 'name' => 'Empty', 'display_name' => 'No values']); // Nothing to choose.
        ProductVariant::factory()->for($product)->priced('12.00')->create(['name' => 'M', 'option_values' => ['Size' => 'M'], 'is_default' => true]);
        ProductVariant::factory()->for($product)->create(['name' => 'Hidden', 'option_values' => ['Size' => 'S'], 'is_active' => false]);

        $this->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page
            ->has('product.options', 1)
            ->where('product.options.0.display_name', 'Garment size')
            ->where('product.options.0.values', ['S', 'M'])
            ->has('product.variants', 1)
            ->where('product.variants.0.option_values', ['Size' => 'M'])
            ->where('product.variants.0.is_default', true)
            ->where('product.variants.0.prices.0.amount', '12.00'));
    }

    #[Test]
    public function the_page_names_the_brand_categories_and_whether_it_ships(): void
    {
        $product = Product::factory()->priced('10.00')->digital()->create();

        $this->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page
            ->where('product.requires_shipping', false)
            ->where('product.can_buy', true)
            ->where('product.brand', null));
    }

    // --- Structured data --------------------------------------------------------------------------

    #[Test]
    public function search_engines_are_told_the_price_and_availability(): void
    {
        $product = Product::factory()->priced('19.99')->stocked(5)->create(['name' => 'Hoodie']);
        $this->picture($product);

        $html = $this->get(route('shop.products.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Offer"', $html);
        $this->assertStringContainsString('"price":"19.99"', $html);
        $this->assertStringContainsString('"priceCurrency":"USD"', $html);
        $this->assertStringContainsString('https:\/\/schema.org\/InStock', $html);
        $this->assertStringContainsString('"image":["', $html);
    }

    #[Test]
    public function a_sold_out_product_is_marked_out_of_stock_to_search_engines(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(0)->create();

        $html = $this->get(route('shop.products.show', $product))->getContent();

        $this->assertStringContainsString('https:\/\/schema.org\/OutOfStock', $html);
    }

    #[Test]
    public function a_product_that_cannot_be_bought_makes_no_offer(): void
    {
        $product = Product::factory()->create(); // No price.

        $html = $this->get(route('shop.products.show', $product))->getContent();

        $this->assertStringNotContainsString('"@type":"Offer"', $html);
        $this->assertStringContainsString('"@type":"Product"', $html);
    }

    #[Test]
    public function the_offer_is_the_cheapest_price_a_shopper_could_pay(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->for($product)->priced('30.00')->create();
        ProductVariant::factory()->for($product)->priced('12.50')->create();

        $this->assertStringContainsString('"price":"12.50"', $this->get(route('shop.products.show', $product))->getContent());
    }

    // --- The cart ---------------------------------------------------------------------------------

    #[Test]
    public function the_cart_shows_each_lines_picture(): void
    {
        $with = Product::factory()->priced('10.00')->stocked(5)->create(['name' => 'With']);
        $main = $this->picture($with, 0);
        $this->picture($with, 1);
        $without = Product::factory()->priced('5.00')->stocked(5)->create(['name' => 'Without']);
        $cart = $this->cartWith($with);
        CartManager::addItem($cart, $without, null, $without->prices()->first(), 1);

        $items = collect(CartManager::summary($cart->fresh())['items'])->pluck('image', 'name')->all();

        $this->assertSame(['With' => $main->thumbUrl(), 'Without' => null], $items);
    }

    #[Test]
    public function the_cart_page_receives_the_pictures(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(5)->create();
        $image = $this->picture($product);
        $this->cartWith($product);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('cart.items.0.image', $image->thumbUrl()));
    }

    #[Test]
    public function looking_up_pictures_for_a_cart_takes_one_query_however_many_lines(): void
    {
        $cart = $this->cartWith(Product::factory()->priced('1.00')->stocked(9)->create());

        foreach (range(1, 5) as $n) {
            $product = Product::factory()->priced('1.00')->stocked(9)->create();
            $this->picture($product);
            CartManager::addItem($cart, $product, null, $product->prices()->first(), 1);
        }

        $cart = $cart->fresh(['items.product', 'items.variant']);
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'product_images')) {
                $queries++;
            }
        });

        CartManager::summary($cart);

        $this->assertSame(1, $queries);
    }
}
