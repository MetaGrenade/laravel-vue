<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxonomyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * @return array<string, array{0: string, 1: class-string, 2: string}>
     */
    public static function types(): array
    {
        return [
            'brands' => ['brands', Brand::class, 'brand'],
            'categories' => ['categories', ProductCategory::class, 'category'],
            'tags' => ['tags', ProductTag::class, 'tag'],
        ];
    }

    #[Test]
    public function the_page_needs_a_signed_in_user_with_commerce_access(): void
    {
        $this->get(route('acp.commerce.taxonomy.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('acp.commerce.taxonomy.index'))->assertForbidden();
    }

    #[Test]
    public function the_page_lists_all_three_with_how_many_products_use_each(): void
    {
        $acme = Brand::create(['name' => 'Acme', 'slug' => 'acme']);
        $clothing = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing', 'description' => 'Things to wear']);
        $sale = ProductTag::create(['name' => 'Sale', 'slug' => 'sale']);
        $hoodie = Product::factory()->create(['brand_id' => $acme->id]);
        $hoodie->categories()->attach($clothing);
        $hoodie->tags()->attach($sale);
        Product::factory()->create(['brand_id' => $acme->id])->categories()->attach($clothing);

        $this->actingAs($this->admin())->get(route('acp.commerce.taxonomy.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/CommerceTaxonomy')
                ->where('brands.0', ['id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'description' => null, 'products_count' => 2])
                ->where('categories.0.description', 'Things to wear')
                ->where('categories.0.products_count', 2)
                ->where('tags.0.products_count', 1)
                ->where('can', ['create' => true, 'edit' => true, 'delete' => true]));
    }

    #[Test]
    #[DataProvider('types')]
    public function an_entry_is_added_with_an_address_made_from_its_name(string $type, string $model, string $label): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.taxonomy.store', $type), ['name' => 'Warm Things', 'description' => 'Cosy'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', ucfirst($label).' added.');
        $this->post(route('acp.commerce.taxonomy.store', $type), ['name' => 'Warm Things'])->assertSessionHasNoErrors();

        $this->assertSame(['warm-things', 'warm-things-2'], $model::orderBy('id')->pluck('slug')->all());
        $this->assertSame('Cosy', $model::orderBy('id')->first()->description);
    }

    #[Test]
    #[DataProvider('types')]
    public function an_entry_is_validated(string $type, string $model, string $label): void
    {
        $model::create(['name' => 'Existing', 'slug' => 'existing']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.taxonomy.store', $type), ['name' => ''])->assertSessionHasErrors('name');
        $this->post(route('acp.commerce.taxonomy.store', $type), ['name' => 'New', 'slug' => 'Existing'])
            ->assertSessionHasErrors(['slug' => 'Another entry already uses this address.']);
        $this->post(route('acp.commerce.taxonomy.store', $type), ['name' => 'New', 'description' => str_repeat('a', 1001)])->assertSessionHasErrors('description');

        $this->assertSame(1, $model::count(), "no $label was added");
    }

    #[Test]
    public function an_address_only_has_to_be_unique_within_its_own_kind(): void
    {
        Brand::create(['name' => 'Sale', 'slug' => 'sale']);

        $this->actingAs($this->admin())->post(route('acp.commerce.taxonomy.store', 'tags'), ['name' => 'Sale', 'slug' => 'sale'])->assertSessionHasNoErrors();

        $this->assertSame(1, ProductTag::count());
    }

    #[Test]
    #[DataProvider('types')]
    public function an_entry_is_edited(string $type, string $model, string $label): void
    {
        $entry = $model::create(['name' => 'Old', 'slug' => 'old']);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.taxonomy.update', [$type, $entry->id]), ['name' => 'New', 'slug' => 'brand-new', 'description' => 'Changed'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', ucfirst($label).' saved.');

        $entry->refresh();
        $this->assertSame(['New', 'brand-new', 'Changed'], [$entry->name, $entry->slug, $entry->description]);

        // An empty address keeps the old one, and an entry may keep its own.
        $this->put(route('acp.commerce.taxonomy.update', [$type, $entry->id]), ['name' => 'Newer', 'slug' => ''])->assertSessionHasNoErrors();
        $this->put(route('acp.commerce.taxonomy.update', [$type, $entry->id]), ['name' => 'Newer', 'slug' => 'brand-new'])->assertSessionHasNoErrors();
        $this->assertSame('brand-new', $entry->fresh()->slug);

        $other = $model::create(['name' => 'Other', 'slug' => 'other']);
        $this->put(route('acp.commerce.taxonomy.update', [$type, $other->id]), ['name' => 'Other', 'slug' => 'brand-new'])->assertSessionHasErrors('slug');
    }

    #[Test]
    public function editing_something_that_is_not_there_is_a_404(): void
    {
        $this->actingAs($this->admin())->put(route('acp.commerce.taxonomy.update', ['brands', 999]), ['name' => 'X'])->assertNotFound();
        $this->delete(route('acp.commerce.taxonomy.destroy', ['tags', 999]))->assertNotFound();
    }

    #[Test]
    public function an_unknown_kind_is_not_found(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/acp/commerce/taxonomy/products', ['name' => 'X'])->assertNotFound();
        $this->put('/acp/commerce/taxonomy/users/1', ['name' => 'X'])->assertNotFound();
        $this->delete('/acp/commerce/taxonomy/users/1')->assertNotFound();
    }

    #[Test]
    public function deleting_a_brand_leaves_its_products_without_one(): void
    {
        $brand = Brand::create(['name' => 'Acme', 'slug' => 'acme']);
        $product = Product::factory()->create(['brand_id' => $brand->id]);

        $this->actingAs($this->admin())->delete(route('acp.commerce.taxonomy.destroy', ['brands', $brand->id]))->assertSessionHas('success', 'Brand deleted.');

        $this->assertSame(0, Brand::count());
        $this->assertNull($product->fresh()->brand_id);
    }

    #[Test]
    public function deleting_a_category_or_tag_unlinks_it_from_products(): void
    {
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $tag = ProductTag::create(['name' => 'Sale', 'slug' => 'sale']);
        $product = Product::factory()->create();
        $product->categories()->attach($category);
        $product->tags()->attach($tag);
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('acp.commerce.taxonomy.destroy', ['categories', $category->id]))->assertSessionHas('success');
        $this->delete(route('acp.commerce.taxonomy.destroy', ['tags', $tag->id]))->assertSessionHas('success');

        $this->assertNotNull($product->fresh());
        $this->assertSame(0, $product->categories()->count());
        $this->assertSame(0, $product->tags()->count());
    }

    #[Test]
    public function each_action_has_its_own_permission(): void
    {
        $brand = Brand::create(['name' => 'Acme', 'slug' => 'acme']);
        $editor = User::factory()->create()->assignRole('editor');
        $editor->givePermissionTo(['commerce.acp.view', 'commerce.acp.edit']);

        $this->actingAs($editor)->get(route('acp.commerce.taxonomy.index'))->assertOk();
        $this->put(route('acp.commerce.taxonomy.update', ['brands', $brand->id]), ['name' => 'Acme Ltd'])->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.taxonomy.store', 'brands'), ['name' => 'New'])->assertForbidden();
        $this->delete(route('acp.commerce.taxonomy.destroy', ['brands', $brand->id]))->assertForbidden();

        $this->assertSame('Acme Ltd', $brand->fresh()->name);
    }

    #[Test]
    public function the_shop_filters_use_what_is_managed_here(): void
    {
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        Product::factory()->create(['name' => 'Hoodie'])->categories()->attach($category);
        Product::factory()->create(['name' => 'Mug']);

        $this->get(route('shop.index', ['category' => [$category->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Hoodie')
                ->where('categories.0.name', 'Clothing'));
    }
}
