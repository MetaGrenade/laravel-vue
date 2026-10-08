<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductTag;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class ProductManagementTest extends TestCase
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
     * Staff with a limited role. (The admin role passes every permission check, so it cannot test them.)
     *
     * @param  list<string>  $permissions
     */
    private function staffWith(array $permissions): User
    {
        $user = User::factory()->create()->assignRole('editor');
        $user->givePermissionTo($permissions);

        return $user;
    }

    // --- Access -----------------------------------------------------------------------------------

    #[Test]
    public function the_pages_need_a_signed_in_user_with_commerce_access(): void
    {
        $product = Product::factory()->create();

        $this->get(route('acp.commerce.products.index'))->assertRedirect(route('login'));
        $this->get(route('acp.commerce.products.edit', $product))->assertRedirect(route('login'));

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('acp.commerce.products.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('acp.commerce.products.edit', $product))->assertForbidden();
    }

    #[Test]
    public function each_action_has_its_own_permission(): void
    {
        $product = Product::factory()->create();
        $viewer = $this->staffWith(['commerce.acp.view']);

        $this->actingAs($viewer)->get(route('acp.commerce.products.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.commerce.products.edit', $product))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'edit' => false, 'delete' => false]));

        $this->actingAs($viewer)->get(route('acp.commerce.products.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.products.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->put(route('acp.commerce.products.update', $product), ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.products.destroy', $product))->assertForbidden();

        $editor = $this->staffWith(['commerce.acp.view', 'commerce.acp.edit']);
        $this->actingAs($editor)->put(route('acp.commerce.products.update', $product), ['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->delete(route('acp.commerce.products.destroy', $product))->assertForbidden();

        $this->assertSame('Renamed', $product->fresh()->name);
    }

    // --- The list ---------------------------------------------------------------------------------

    #[Test]
    public function the_list_shows_price_stock_and_whether_a_product_can_be_bought(): void
    {
        Product::factory()->priced('40.00')->stocked(30)->create(['name' => 'Hoodie']);
        Product::factory()->create(['name' => 'Mug']); // no price
        $shirt = Product::factory()->create(['name' => 'Shirt']);
        ProductVariant::factory()->for($shirt)->priced('15.00')->stocked(2)->create(['name' => 'S']);
        ProductVariant::factory()->for($shirt)->priced('18.50')->stocked(0)->create(['name' => 'XL']);

        $this->actingAs($this->admin())->get(route('acp.commerce.products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/CommerceProducts')
                ->where('currency', 'USD')
                ->has('products.data', 3)
                ->where('products.data.0.name', 'Hoodie')
                ->where('products.data.0.price', ['from' => '40.00', 'to' => '40.00'])
                ->where('products.data.0.stock', ['tracked' => true, 'total' => 30, 'status' => 'in_stock'])
                ->where('products.data.0.sellable', true)
                ->where('products.data.1.name', 'Mug')
                ->where('products.data.1.price', ['from' => null, 'to' => null])
                ->where('products.data.1.stock.status', 'untracked')
                ->where('products.data.1.sellable', false)
                ->where('products.data.2.name', 'Shirt')
                ->where('products.data.2.variants_count', 2)
                ->where('products.data.2.price', ['from' => '15.00', 'to' => '18.50'])
                ->where('products.data.2.stock', ['tracked' => true, 'total' => 2, 'status' => 'low'])
                ->where('products.meta.total', 3));

    }

    #[Test]
    public function stock_that_has_run_out_is_shown_as_out_unless_it_can_be_backordered(): void
    {
        Product::factory()->priced('10.00')->stocked(0)->create(['name' => 'A out']);
        Product::factory()->priced('10.00')->stocked(0, allowBackorder: true)->create(['name' => 'B backorder']);

        $this->actingAs($this->admin())->get(route('acp.commerce.products.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.stock.status', 'out')
                ->where('products.data.1.stock.status', 'in_stock'));
    }

    #[Test]
    public function a_product_that_is_archived_or_priced_in_another_currency_cannot_be_bought(): void
    {
        Product::factory()->priced('10.00')->inactive()->create(['name' => 'A archived']);
        Product::factory()->priced('10.00', 'EUR')->create(['name' => 'B euro']);

        $this->actingAs($this->admin())->get(route('acp.commerce.products.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.sellable', false)
                ->where('products.data.1.sellable', false)
                ->where('products.data.1.price', ['from' => null, 'to' => null]));
    }

    #[Test]
    public function the_list_can_be_searched_and_filtered(): void
    {
        $acme = Brand::create(['name' => 'Acme', 'slug' => 'acme']);
        Product::factory()->create(['name' => 'Red Hoodie', 'slug' => 'red-hoodie', 'brand_id' => $acme->id]);
        Product::factory()->create(['name' => 'Blue Mug', 'slug' => 'blue-mug']);
        Product::factory()->inactive()->create(['name' => 'Old Poster', 'slug' => 'old-poster']);
        $withSku = Product::factory()->create(['name' => 'Plain Tee', 'slug' => 'plain-tee']);
        ProductVariant::factory()->for($withSku)->create(['name' => 'M', 'sku' => 'TEE-M-777']);
        $admin = $this->admin();

        $names = fn ($response) => collect($response->viewData('page')['props']['products']['data'])->pluck('name')->all();

        $this->actingAs($admin);
        $this->assertSame(['Red Hoodie'], $names($this->get(route('acp.commerce.products.index', ['search' => 'hoodie']))));
        $this->assertSame(['Blue Mug'], $names($this->get(route('acp.commerce.products.index', ['search' => 'blue-mug']))));
        $this->assertSame(['Plain Tee'], $names($this->get(route('acp.commerce.products.index', ['search' => 'TEE-M-777']))), 'found by a variant SKU');
        $this->assertSame(['Old Poster'], $names($this->get(route('acp.commerce.products.index', ['status' => 'archived']))));
        $this->assertSame(['Blue Mug', 'Plain Tee', 'Red Hoodie'], $names($this->get(route('acp.commerce.products.index', ['status' => 'active']))));
        $this->assertSame(['Red Hoodie'], $names($this->get(route('acp.commerce.products.index', ['brand' => $acme->id]))));
    }

    #[Test]
    public function the_list_is_paginated(): void
    {
        Product::factory()->count(25)->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.products.index'))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 20)->where('products.meta.last_page', 2));

        $this->get(route('acp.commerce.products.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 5));
    }

    // --- Creating ---------------------------------------------------------------------------------

    #[Test]
    public function a_product_is_created_and_the_admin_is_taken_to_its_page(): void
    {
        $brand = Brand::create(['name' => 'Acme', 'slug' => 'acme']);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $tag = ProductTag::create(['name' => 'Sale', 'slug' => 'sale']);

        $response = $this->actingAs($this->admin())->post(route('acp.commerce.products.store'), [
            'name' => 'Warm Hoodie',
            'slug' => 'Warm Hoodie!',
            'description' => 'A warm hoodie.',
            'brand_id' => $brand->id,
            'category_ids' => [$category->id],
            'tag_ids' => [$tag->id],
            'is_active' => false,
            'requires_shipping' => true,
            'is_taxable' => false,
        ]);

        $product = Product::where('name', 'Warm Hoodie')->firstOrFail();
        $response->assertRedirect(route('acp.commerce.products.edit', $product))->assertSessionHas('success');

        $this->assertSame('warm-hoodie', $product->slug, 'the address is made URL-safe');
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame([$category->id], $product->categories()->pluck('product_categories.id')->all());
        $this->assertSame([$tag->id], $product->tags()->pluck('product_tags.id')->all());
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->is_taxable);
    }

    #[Test]
    public function the_address_is_made_from_the_name_when_left_empty_and_never_clashes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.products.store'), ['name' => 'Blue Mug'])->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.products.store'), ['name' => 'Blue Mug'])->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.products.store'), ['name' => '!!!'])->assertSessionHasNoErrors();

        $this->assertSame(['blue-mug', 'blue-mug-2', 'product'], Product::orderBy('id')->pluck('slug')->all());
    }

    #[Test]
    public function a_typed_address_must_be_unused(): void
    {
        Product::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->admin())->post(route('acp.commerce.products.store'), ['name' => 'Other', 'slug' => 'Taken'])
            ->assertSessionHasErrors(['slug' => 'Another product already uses this address.']);

        $this->assertSame(1, Product::count());
    }

    #[Test]
    public function the_product_form_is_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.products.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->post(route('acp.commerce.products.store'), ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
        $this->post(route('acp.commerce.products.store'), ['name' => 'X', 'brand_id' => 999])->assertSessionHasErrors('brand_id');
        $this->post(route('acp.commerce.products.store'), ['name' => 'X', 'category_ids' => [999]])->assertSessionHasErrors('category_ids.0');
        $this->post(route('acp.commerce.products.store'), ['name' => 'X', 'tag_ids' => [999]])->assertSessionHasErrors('tag_ids.0');
        $this->post(route('acp.commerce.products.store'), ['name' => 'X', 'is_taxable' => 'maybe'])->assertSessionHasErrors('is_taxable');

        $this->assertSame(0, Product::count());
    }

    // --- The product page -------------------------------------------------------------------------

    #[Test]
    public function the_product_page_gathers_everything_about_the_product(): void
    {
        $brand = Brand::create(['name' => 'Acme', 'slug' => 'acme']);
        $product = Product::factory()->priced('40.00')->stocked(12)->create(['name' => 'Hoodie', 'slug' => 'hoodie', 'brand_id' => $brand->id]);
        $product->categories()->attach(ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']));
        $product->prices()->first()->update(['compare_at_amount' => '55.00']);

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/CommerceProductEdit')
                ->where('product.name', 'Hoodie')
                ->where('product.slug', 'hoodie')
                ->where('product.brand_id', $brand->id)
                ->where('product.shop_url', route('shop.products.show', $product))
                ->where('currency', 'USD')
                ->where('prices.0.amount', '40.00')
                ->where('prices.0.compare_at_amount', '55.00')
                ->where('prices.0.usable', true)
                ->where('stock.quantity', 12)
                ->where('stock.allow_backorder', false)
                ->where('stock.movements', [])
                ->where('readiness', [
                    ['ok' => true, 'text' => 'Switched on in the shop'],
                    ['ok' => true, 'text' => 'Has an active price in USD'],
                ])
                ->where('deletion_block', null)
                ->where('missing_variants', 0)
                ->where('can', ['create' => true, 'edit' => true, 'delete' => true]));
    }

    #[Test]
    public function the_page_says_what_is_missing_before_a_product_can_be_bought(): void
    {
        $product = Product::factory()->inactive()->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.shop_url', null)
                ->where('readiness.0.ok', false)
                ->where('readiness.1', ['ok' => false, 'text' => 'No active price in USD: it cannot be bought']));
    }

    #[Test]
    public function the_page_warns_about_variants_with_nothing_to_fall_back_on(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->for($product)->priced('10.00')->create();
        ProductVariant::factory()->for($product)->create(); // No price of its own, and the product has none.

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('readiness.2.ok', false)->where('readiness.2.text', fn ($text) => str_starts_with($text, '1 variant has no price')));
    }

    #[Test]
    public function the_page_marks_a_variant_that_has_been_ordered(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->priced('10.00')->stocked(5)->create(['name' => 'M']);
        $other = ProductVariant::factory()->for($product)->priced('10.00')->stocked(5)->create(['name' => 'L']);
        $this->cartWith($variant);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('deletion_block', fn ($reason) => str_contains($reason, 'has been ordered'))
                ->where('variants', fn ($variants) => collect($variants)->firstWhere('id', $variant->id)['ordered'] === true
                    && collect($variants)->firstWhere('id', $other->id)['ordered'] === false));
    }

    // --- Updating ---------------------------------------------------------------------------------

    #[Test]
    public function a_product_can_be_edited(): void
    {
        $product = Product::factory()->create(['name' => 'Old', 'slug' => 'old']);
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $tag = ProductTag::create(['name' => 'Sale', 'slug' => 'sale']);
        $brand = Brand::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($this->admin())->put(route('acp.commerce.products.update', $product), [
            'name' => 'New',
            'slug' => 'brand-new',
            'description' => 'Fresh.',
            'brand_id' => $brand->id,
            'category_ids' => [$category->id],
            'tag_ids' => [$tag->id],
            'is_active' => false,
            'requires_shipping' => false,
            'is_taxable' => false,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $product->refresh();
        $this->assertSame(['New', 'brand-new', 'Fresh.'], [$product->name, $product->slug, $product->description]);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->requires_shipping);
        $this->assertFalse($product->is_taxable);
        $this->assertSame([$category->id], $product->categories()->pluck('product_categories.id')->all());
        $this->assertSame([$tag->id], $product->tags()->pluck('product_tags.id')->all());
    }

    #[Test]
    public function an_empty_address_keeps_the_existing_one(): void
    {
        $product = Product::factory()->create(['slug' => 'keep-me']);

        $this->actingAs($this->admin())->put(route('acp.commerce.products.update', $product), ['name' => 'Renamed', 'slug' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('keep-me', $product->fresh()->slug, 'a product link should not change by accident');
    }

    #[Test]
    public function a_product_can_keep_its_own_address_but_not_take_another(): void
    {
        $product = Product::factory()->create(['slug' => 'mine']);
        Product::factory()->create(['slug' => 'theirs']);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.products.update', $product), ['name' => 'Same', 'slug' => 'mine'])->assertSessionHasNoErrors();
        $this->put(route('acp.commerce.products.update', $product), ['name' => 'Same', 'slug' => 'theirs'])->assertSessionHasErrors('slug');

        $this->assertSame('mine', $product->fresh()->slug);
    }

    #[Test]
    public function categories_and_tags_are_left_alone_when_the_form_does_not_send_them(): void
    {
        $product = Product::factory()->create();
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $product->categories()->attach($category);

        $this->actingAs($this->admin())->put(route('acp.commerce.products.update', $product), ['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame(1, $product->categories()->count());

        $this->put(route('acp.commerce.products.update', $product), ['name' => 'Renamed', 'category_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, $product->categories()->count(), 'an empty list clears them');
    }

    #[Test]
    public function archiving_a_product_stops_it_being_bought(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(5)->create();
        $this->cartWith($product);

        $this->actingAs($this->admin())->put(route('acp.commerce.products.update', $product), ['name' => $product->name, 'is_active' => false])->assertSessionHasNoErrors();

        $this->get(route('shop.products.show', $product))->assertNotFound();
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    // --- Deleting ---------------------------------------------------------------------------------

    #[Test]
    public function a_product_that_was_never_ordered_can_be_deleted_with_everything_it_owns(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(5)->create();
        $option = ProductOption::create(['product_id' => $product->id, 'name' => 'Size', 'display_name' => 'Size']);
        $option->values()->create(['value' => 'M']);
        $variant = ProductVariant::factory()->for($product)->priced('12.00')->stocked(3)->create();
        $product->categories()->attach(ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']));
        $keep = Product::factory()->priced('99.00')->stocked(1)->create();

        $this->actingAs($this->admin())->delete(route('acp.commerce.products.destroy', $product))
            ->assertRedirect(route('acp.commerce.products.index'))
            ->assertSessionHas('success');

        $this->assertNull(Product::find($product->id));
        $this->assertSame(0, ProductVariant::where('id', $variant->id)->count());
        $this->assertSame(0, ProductOption::count());
        $this->assertSame(1, InventoryItem::count(), 'only the other product keeps stock');
        $this->assertSame(1, Price::count(), 'the product and variant prices go too; they have no foreign key');
        $this->assertSame($keep->id, Price::sole()->priceable_id);
        $this->assertSame(0, DB::table('product_product_category')->count());
    }

    #[Test]
    public function deleting_a_product_takes_it_out_of_carts_and_recounts_them(): void
    {
        $doomed = Product::factory()->priced('10.00')->stocked(5)->create();
        $other = Product::factory()->priced('7.00')->stocked(5)->create();
        $cart = $this->cartWith($doomed, 2);
        $this->post(route('shop.cart.items.store'), ['product_id' => $other->id, 'quantity' => 1])->assertSessionHas('success');
        $this->assertSame('27.00', $cart->fresh()->subtotal);

        $this->actingAs($this->admin())->delete(route('acp.commerce.products.destroy', $doomed))->assertSessionHas('success');

        // The line is gone rather than left behind as a line for nothing.
        $this->assertSame(1, CartItem::count());
        $this->assertSame($other->id, CartItem::sole()->product_id);
        $this->assertSame('7.00', $cart->fresh()->subtotal);
    }

    #[Test]
    public function a_product_that_has_been_ordered_cannot_be_deleted_only_archived(): void
    {
        [$order, , $product] = $this->placeOrder();

        $this->actingAs($this->admin())->delete(route('acp.commerce.products.destroy', $product))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Archive it instead'));

        $this->assertNotNull(Product::find($product->id));
        $this->assertSame($product->id, $order->items()->first()->product_id, 'the order still points at it');
        $this->assertSame(1, InventoryItem::count());
    }

    // --- The overview -----------------------------------------------------------------------------

    #[Test]
    public function the_overview_lists_stock_that_is_running_out(): void
    {
        $low = Product::factory()->create(['name' => 'Nearly gone']);
        ProductVariant::factory()->for($low)->stocked(2)->create(['name' => 'M']);
        Product::factory()->stocked(0)->create(['name' => 'Gone']);
        Product::factory()->stocked(0, allowBackorder: true)->create(['name' => 'Backordered']);
        Product::factory()->stocked(50)->create(['name' => 'Plenty']);

        $this->actingAs($this->admin())->get(route('acp.commerce.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/Commerce')
                ->where('lowStockThreshold', 5)
                ->has('lowStock', 2)
                ->where('lowStock.0.product', 'Gone')
                ->where('lowStock.0.quantity', 0)
                ->where('lowStock.1.product', 'Nearly gone')
                ->where('lowStock.1.variant', 'M')
                ->where('metrics.inventory.out_of_stock', 1)
                ->where('metrics.inventory.on_hand', 52)
                ->where('currency', 'USD'));
    }

    #[Test]
    public function the_threshold_for_low_stock_is_configurable(): void
    {
        config(['commerce.low_stock_threshold' => 20]);
        Product::factory()->stocked(15)->create(['name' => 'Fifteen']);

        $this->actingAs($this->admin())->get(route('acp.commerce.index'))
            ->assertInertia(fn (Assert $page) => $page->has('lowStock', 1)->where('lowStock.0.product', 'Fifteen'));

        $this->get(route('acp.commerce.products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('products.data.0.stock.status', 'low'));
    }
}
