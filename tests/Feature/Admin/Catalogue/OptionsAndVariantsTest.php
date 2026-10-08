<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Commerce\Catalogue\VariantGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class OptionsAndVariantsTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->setUpCommerce();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * A product with a Size option (S, M, L) and optionally a Colour option (Red, Blue).
     */
    private function productWithOptions(bool $colour = false): Product
    {
        $product = Product::factory()->create(['name' => 'Hoodie', 'slug' => 'hoodie']);

        $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Size', 'display_name' => 'Size', 'position' => 1]);
        foreach (['S', 'M', 'L'] as $position => $value) {
            $size->values()->create(['value' => $value, 'position' => $position]);
        }

        if ($colour) {
            $color = ProductOption::create(['product_id' => $product->id, 'name' => 'Colour', 'display_name' => 'Colour', 'position' => 2]);
            foreach (['Red', 'Blue'] as $position => $value) {
                $color->values()->create(['value' => $value, 'position' => $position]);
            }
        }

        return $product;
    }

    // --- Options ----------------------------------------------------------------------------------

    #[Test]
    public function an_option_is_created_with_its_values_from_a_list(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.options.store', $product), [
            'name' => 'Size',
            'values' => "S, M\nL,  M ,",
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $option = $product->options()->sole();
        $this->assertSame('Size', $option->display_name, 'the label defaults to the name');
        $this->assertSame(['S', 'M', 'L'], $option->values()->orderBy('position')->pluck('value')->all(), 'blanks and repeats are dropped');
    }

    #[Test]
    public function options_are_added_after_the_ones_already_there(): void
    {
        $product = $this->productWithOptions();

        $this->actingAs($this->admin())->post(route('acp.commerce.options.store', $product), ['name' => 'Colour', 'display_name' => 'Colour'])->assertSessionHasNoErrors();

        $this->assertSame(['Size', 'Colour'], $product->options()->orderBy('position')->pluck('name')->all());
    }

    #[Test]
    public function an_option_name_is_unique_within_its_product_only(): void
    {
        $product = $this->productWithOptions();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.options.store', $product), ['name' => 'Size'])
            ->assertSessionHasErrors(['name' => 'This product already has an option with that name.']);

        $other = Product::factory()->create();
        $this->post(route('acp.commerce.options.store', $other), ['name' => 'Size'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function renaming_an_option_rewrites_the_variants_made_from_it(): void
    {
        $product = $this->productWithOptions(colour: true);
        $variant = ProductVariant::factory()->for($product)->create(['option_values' => ['Size' => 'M', 'Colour' => 'Red']]);
        $size = $product->options()->where('name', 'Size')->first();

        $this->actingAs($this->admin())->put(route('acp.commerce.options.update', $size), ['name' => 'Fit', 'display_name' => 'Fit'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Fit', $size->fresh()->name);
        // Compared without regard to key order: MySQL's JSON type reorders object keys.
        $this->assertEquals(['Fit' => 'M', 'Colour' => 'Red'], $variant->fresh()->option_values, 'the variant follows the renamed option');
    }

    #[Test]
    public function changing_only_the_label_leaves_the_variants_alone(): void
    {
        $product = $this->productWithOptions();
        $variant = ProductVariant::factory()->for($product)->create(['option_values' => ['Size' => 'M']]);
        $size = $product->options()->first();

        $this->actingAs($this->admin())->put(route('acp.commerce.options.update', $size), ['name' => 'Size', 'display_name' => 'Garment size'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Garment size', $size->fresh()->display_name);
        $this->assertSame(['Size' => 'M'], $variant->fresh()->option_values);
    }

    #[Test]
    public function an_option_that_variants_are_made_from_cannot_be_deleted(): void
    {
        $product = $this->productWithOptions();
        ProductVariant::factory()->for($product)->create(['option_values' => ['Size' => 'M']]);
        $size = $product->options()->first();

        $this->actingAs($this->admin())->delete(route('acp.commerce.options.destroy', $size))
            ->assertSessionHas('error', fn ($message) => str_contains($message, '1 variant is made from Size'));

        $this->assertNotNull($size->fresh());
    }

    #[Test]
    public function an_unused_option_can_be_deleted_with_its_values(): void
    {
        $product = $this->productWithOptions();
        $size = $product->options()->first();

        $this->actingAs($this->admin())->delete(route('acp.commerce.options.destroy', $size))->assertSessionHas('success');

        $this->assertSame(0, ProductOption::count());
        $this->assertSame(0, ProductOptionValue::count());
    }

    // --- Option values ----------------------------------------------------------------------------

    #[Test]
    public function a_value_is_added_renamed_and_deleted(): void
    {
        $product = $this->productWithOptions();
        $size = $product->options()->first();
        $variant = ProductVariant::factory()->for($product)->create(['option_values' => ['Size' => 'M']]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.option-values.store', $size), ['value' => ' XL '])->assertSessionHasNoErrors();
        $xl = $size->values()->where('value', 'XL')->firstOrFail();
        $this->assertSame(3, $xl->position, 'after the existing values');

        $this->put(route('acp.commerce.option-values.update', $xl), ['value' => 'XXL'])->assertSessionHasNoErrors();
        $this->assertSame('XXL', $xl->fresh()->value);

        $m = $size->values()->where('value', 'M')->firstOrFail();
        $this->put(route('acp.commerce.option-values.update', $m), ['value' => 'Medium'])->assertSessionHasNoErrors();
        $this->assertSame(['Size' => 'Medium'], $variant->fresh()->option_values, 'the variant follows the renamed value');

        $this->delete(route('acp.commerce.option-values.destroy', $xl))->assertSessionHas('success');
        $this->assertNull($xl->fresh());
    }

    #[Test]
    public function a_value_that_a_variant_uses_cannot_be_deleted(): void
    {
        $product = $this->productWithOptions();
        ProductVariant::factory()->for($product)->create(['option_values' => ['Size' => 'M']]);
        $m = $product->options()->first()->values()->where('value', 'M')->first();

        $this->actingAs($this->admin())->delete(route('acp.commerce.option-values.destroy', $m))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Size: M'));

        $this->assertNotNull($m->fresh());
    }

    #[Test]
    public function a_value_is_unique_within_its_option(): void
    {
        $product = $this->productWithOptions();
        $size = $product->options()->first();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.option-values.store', $size), ['value' => 'M'])
            ->assertSessionHasErrors(['value' => 'This option already has that value.']);
        $this->post(route('acp.commerce.option-values.store', $size), ['value' => ''])->assertSessionHasErrors('value');

        // Renaming a value to itself is fine; to a sibling is not.
        $m = $size->values()->where('value', 'M')->first();
        $this->put(route('acp.commerce.option-values.update', $m), ['value' => 'M'])->assertSessionHasNoErrors();
        $this->put(route('acp.commerce.option-values.update', $m), ['value' => 'L'])->assertSessionHasErrors('value');
    }

    // --- Variants ---------------------------------------------------------------------------------

    #[Test]
    public function a_variant_is_added_for_a_combination_of_options(): void
    {
        $product = $this->productWithOptions(colour: true);

        $this->actingAs($this->admin())->post(route('acp.commerce.variants.store', $product), [
            'name' => 'Medium red',
            'sku' => 'HOOD-M-RED',
            'option_values' => ['Size' => 'M', 'Colour' => 'Red'],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $variant = $product->variants()->sole();
        $this->assertEquals(['Size' => 'M', 'Colour' => 'Red'], $variant->option_values);
        $this->assertSame('HOOD-M-RED', $variant->sku);
        $this->assertTrue($variant->is_default, 'the first variant is the default');
        $this->assertTrue($variant->is_active);
    }

    #[Test]
    public function a_variant_must_choose_a_real_value_for_every_option(): void
    {
        $product = $this->productWithOptions(colour: true);
        $admin = $this->admin();
        $store = fn (array $values) => $this->actingAs($admin)->post(route('acp.commerce.variants.store', $product), ['name' => 'V', 'option_values' => $values]);

        $store(['Size' => 'M'])->assertSessionHasErrors(['option_values' => 'Choose a value for Colour.']);
        $store(['Size' => 'XXL', 'Colour' => 'Red'])->assertSessionHasErrors('option_values');
        $store(['Size' => 'M', 'Shape' => 'Round', 'Colour' => 'Red'])->assertSessionHasErrors('option_values');
        $store([])->assertSessionHasErrors('option_values');

        $this->assertSame(0, ProductVariant::count());
    }

    #[Test]
    public function a_product_without_options_only_has_variants_without_options(): void
    {
        $product = Product::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.variants.store', $product), ['name' => 'Standard'])->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.variants.store', $product), ['name' => 'Odd', 'option_values' => ['Size' => 'M']])
            ->assertSessionHasErrors('option_values');

        $this->assertSame(1, $product->variants()->count());
        $this->assertNull($product->variants()->first()->option_values);
    }

    #[Test]
    public function no_two_variants_share_a_combination_or_a_sku(): void
    {
        $product = $this->productWithOptions();
        $variant = ProductVariant::factory()->for($product)->create(['option_values' => ['Size' => 'M'], 'sku' => 'TAKEN']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.variants.store', $product), ['name' => 'Again', 'option_values' => ['Size' => 'M']])
            ->assertSessionHasErrors(['option_values' => 'Another variant already has this combination.']);
        $this->post(route('acp.commerce.variants.store', $product), ['name' => 'Large', 'sku' => 'TAKEN', 'option_values' => ['Size' => 'L']])
            ->assertSessionHasErrors(['sku' => 'Another variant already uses this SKU.']);

        // Editing a variant does not clash with itself.
        $this->put(route('acp.commerce.variants.update', $variant), ['name' => 'Renamed', 'sku' => 'TAKEN', 'option_values' => ['Size' => 'M']])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $variant->fresh()->name);
    }

    #[Test]
    public function only_one_variant_is_the_default(): void
    {
        $product = $this->productWithOptions();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('acp.commerce.variants.store', $product), ['name' => 'S', 'option_values' => ['Size' => 'S']]);
        $this->post(route('acp.commerce.variants.store', $product), ['name' => 'M', 'option_values' => ['Size' => 'M'], 'is_default' => true]);
        $first = $product->variants()->where('name', 'S')->first();
        $second = $product->variants()->where('name', 'M')->first();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->put(route('acp.commerce.variants.update', $first), ['name' => 'S', 'option_values' => ['Size' => 'S'], 'is_default' => true]);

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
    }

    #[Test]
    public function a_variant_can_be_switched_off(): void
    {
        $product = $this->productWithOptions();
        $variant = ProductVariant::factory()->for($product)->create(['option_values' => ['Size' => 'M']]);

        $this->actingAs($this->admin())->put(route('acp.commerce.variants.update', $variant), [
            'name' => $variant->name, 'option_values' => ['Size' => 'M'], 'is_active' => false,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($variant->fresh()->is_active);
    }

    // --- Generating variants ----------------------------------------------------------------------

    #[Test]
    public function variants_are_generated_for_every_combination(): void
    {
        $product = $this->productWithOptions(colour: true);

        $this->actingAs($this->admin())->post(route('acp.commerce.variants.generate', $product))
            ->assertSessionHas('success', '6 variants created. Give them prices and stock.');

        $variants = $product->variants()->orderBy('id')->get();
        $this->assertCount(6, $variants);
        $this->assertSame('S / Red', $variants[0]->name);
        $this->assertEquals(['Size' => 'S', 'Colour' => 'Red'], $variants[0]->option_values);
        $this->assertSame('HOODIE-S-RED', $variants[0]->sku);
        $this->assertSame(['S / Red', 'S / Blue', 'M / Red', 'M / Blue', 'L / Red', 'L / Blue'], $variants->pluck('name')->all());
        $this->assertSame([true, false, false, false, false, false], $variants->pluck('is_default')->all());
    }

    #[Test]
    public function generating_again_only_adds_what_is_missing(): void
    {
        $product = $this->productWithOptions();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('acp.commerce.variants.generate', $product));
        $this->assertSame(3, $product->variants()->count());

        $this->post(route('acp.commerce.variants.generate', $product))->assertSessionHas('info', 'Every combination already has a variant.');
        $this->assertSame(3, $product->variants()->count());

        $product->options()->first()->values()->create(['value' => 'XL', 'position' => 3]);
        $this->post(route('acp.commerce.variants.generate', $product))->assertSessionHas('success', '1 variant created. Give them prices and stock.');
        $this->assertSame(4, $product->variants()->count());
    }

    #[Test]
    public function the_product_page_counts_the_variants_still_to_generate(): void
    {
        $product = $this->productWithOptions(colour: true);
        ProductVariant::factory()->for($product)->create(['option_values' => ['Colour' => 'Red', 'Size' => 'S']]); // Key order does not matter.

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('missing_variants', 5)->where('variant_limit', 100));
    }

    #[Test]
    public function an_existing_variant_with_the_same_pairs_in_another_order_is_not_duplicated(): void
    {
        $product = $this->productWithOptions(colour: true);
        ProductVariant::factory()->for($product)->create(['option_values' => ['Colour' => 'Red', 'Size' => 'S']]);

        $this->actingAs($this->admin())->post(route('acp.commerce.variants.generate', $product))->assertSessionHas('success', '5 variants created. Give them prices and stock.');

        $this->assertSame(6, $product->variants()->count());
    }

    #[Test]
    public function a_generated_sku_never_clashes_with_an_existing_one(): void
    {
        $product = $this->productWithOptions();
        ProductVariant::factory()->create(['sku' => 'HOODIE-S']);

        $this->actingAs($this->admin())->post(route('acp.commerce.variants.generate', $product))->assertSessionHas('success');

        $this->assertSame(['HOODIE-S-2', 'HOODIE-M', 'HOODIE-L'], $product->variants()->orderBy('id')->pluck('sku')->all());
    }

    #[Test]
    public function too_many_combinations_are_refused(): void
    {
        $product = Product::factory()->create();
        foreach (['A', 'B', 'C'] as $name) {
            $option = ProductOption::create(['product_id' => $product->id, 'name' => $name, 'display_name' => $name]);
            foreach (range(1, 5) as $n) {
                $option->values()->create(['value' => (string) $n]);
            }
        }

        $this->actingAs($this->admin())->post(route('acp.commerce.variants.generate', $product))
            ->assertSessionHas('error', fn ($message) => str_contains($message, '125 combinations'));

        $this->assertSame(0, $product->variants()->count());
        $this->assertSame(125, count(app(VariantGenerator::class)->combinations($product)));
    }

    #[Test]
    public function an_option_with_no_values_does_not_make_the_combinations_empty(): void
    {
        $product = $this->productWithOptions();
        ProductOption::create(['product_id' => $product->id, 'name' => 'Colour', 'display_name' => 'Colour']); // No values yet.

        $this->actingAs($this->admin())->post(route('acp.commerce.variants.generate', $product))->assertSessionHas('success', '3 variants created. Give them prices and stock.');
    }

    // --- Deleting variants ------------------------------------------------------------------------

    #[Test]
    public function deleting_a_variant_removes_its_prices_stock_and_cart_lines(): void
    {
        $product = Product::factory()->priced('20.00')->create();
        $variant = ProductVariant::factory()->for($product)->priced('25.00')->stocked(4)->create(['is_default' => true]);
        $other = ProductVariant::factory()->for($product)->priced('30.00')->stocked(4)->create();
        $cart = $this->cartWith($variant, 2);
        $this->post(route('shop.cart.items.store'), ['product_id' => $product->id, 'product_variant_id' => $other->id, 'quantity' => 1]);

        $this->actingAs($this->admin())->delete(route('acp.commerce.variants.destroy', $variant))->assertSessionHas('success');

        $this->assertNull($variant->fresh());
        $this->assertSame(0, Price::where('priceable_type', $variant->getMorphClass())->where('priceable_id', $variant->id)->count());
        $this->assertSame(0, InventoryItem::where('product_variant_id', $variant->id)->count());
        // The cart line goes; it must not turn into a line for the base product at the base price.
        $this->assertSame([$other->id], CartItem::pluck('product_variant_id')->all());
        $this->assertSame('30.00', $cart->fresh()->subtotal);
        $this->assertTrue($other->fresh()->is_default, 'the product keeps a default variant');
    }

    #[Test]
    public function a_variant_that_has_been_ordered_cannot_be_deleted_only_switched_off(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->priced('25.00')->stocked(4)->create();
        $this->cartWith($variant);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $this->assertSame(1, Order::count());

        $this->actingAs($this->admin())->delete(route('acp.commerce.variants.destroy', $variant))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Switch it off instead'));

        $this->assertNotNull($variant->fresh());
    }

    // --- Switched-off variants in the shop --------------------------------------------------------

    #[Test]
    public function a_switched_off_variant_cannot_be_added_to_a_cart(): void
    {
        $product = Product::factory()->priced('10.00')->create();
        $variant = ProductVariant::factory()->for($product)->stocked(5)->create(['is_active' => false]);

        $this->post(route('shop.cart.items.store'), ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertSessionHas('error', 'This option is not available.');

        $this->assertSame(0, CartItem::count());
    }

    #[Test]
    public function a_variant_switched_off_after_it_was_carted_stops_checkout(): void
    {
        $product = Product::factory()->priced('10.00')->create();
        $variant = ProductVariant::factory()->for($product)->stocked(5)->create();
        $this->cartWith($variant);
        $variant->update(['is_active' => false]);

        $this->post(route('shop.checkout.store'), $this->checkoutPayload())
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'no longer available in the option you chose'));

        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function the_shop_only_lists_variants_that_are_on(): void
    {
        $product = Product::factory()->priced('10.00')->create();
        ProductVariant::factory()->for($product)->create(['name' => 'On']);
        ProductVariant::factory()->for($product)->create(['name' => 'Off', 'is_active' => false]);

        $this->get(route('shop.index'))->assertInertia(fn (Assert $page) => $page
            ->has('products.data.0.variants', 1)
            ->where('products.data.0.variants.0.name', 'On'));

        $this->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page
            ->has('product.variants', 1)
            ->where('product.variants.0.name', 'On'));
    }
}
