<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_archived_product_is_hidden_on_the_shop_and_editing_needs_edit(): void
    {
        $archived = Product::create([
            'name' => 'Archived hoodie',
            'slug' => 'archived-hoodie',
            'is_active' => false,
        ]);
        $forSale = Product::create([
            'name' => 'Hoodie',
            'slug' => 'hoodie',
            'is_active' => true,
        ]);
        $shopper = User::factory()->create();

        $this->assertFalse(app(ProductPolicy::class)->view($shopper, $archived));
        $this->assertTrue(app(ProductPolicy::class)->view($shopper, $forSale));
        $this->get(route('shop.products.show', $archived))->assertNotFound();
        $this->get(route('shop.products.show', $forSale))->assertOk();

        Role::findOrCreate('editor');
        Permission::findOrCreate('commerce.acp.view');
        Permission::findOrCreate('commerce.acp.edit');

        $editor = User::factory()->create()->assignRole('editor');
        $editor->givePermissionTo('commerce.acp.view');

        $this->assertFalse($editor->can('update', $forSale));

        $editor->givePermissionTo('commerce.acp.edit');

        $this->assertTrue($editor->fresh()->can('update', $forSale));
    }
}
