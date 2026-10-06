<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_active_products(): void
    {
        Product::create(['name' => 'Hoodie', 'slug' => 'hoodie', 'is_active' => true]);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('commerce/Catalog'));
    }

    public function test_active_product_page_renders_with_structured_data(): void
    {
        $product = Product::create([
            'name' => 'Hoodie',
            'slug' => 'hoodie',
            'description' => '<p>A <strong>warm</strong> hoodie.</p>',
            'is_active' => true,
        ]);

        $this->get(route('shop.products.show', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('commerce/ProductDetail'))
            ->assertSee('"@type":"Product"', false)
            ->assertSee('<meta name="description" content="A warm hoodie."', false);
    }

    public function test_inactive_products_are_not_publicly_visible(): void
    {
        $product = Product::create(['name' => 'Retired', 'slug' => 'retired', 'is_active' => false]);

        $this->get(route('shop.products.show', $product))->assertNotFound();
    }
}
