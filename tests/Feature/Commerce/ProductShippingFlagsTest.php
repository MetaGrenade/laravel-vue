<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductShippingFlagsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole('admin');
    }

    #[Test]
    public function a_new_product_is_shipped_and_taxed_by_default(): void
    {
        $product = Product::create(['name' => 'Mug', 'slug' => 'mug'])->fresh();

        $this->assertTrue($product->requires_shipping);
        $this->assertTrue($product->is_taxable);
    }

    #[Test]
    public function an_administrator_can_create_a_download_that_needs_no_shipping(): void
    {
        $this->actingAs($this->admin())
            ->post(route('acp.commerce.products.store'), [
                'name' => 'E-book',
                'slug' => 'e-book',
                'is_active' => true,
                'requires_shipping' => false,
                'is_taxable' => false,
            ])
            ->assertSessionHasNoErrors();

        $product = Product::where('slug', 'e-book')->firstOrFail();

        $this->assertFalse($product->requires_shipping);
        $this->assertFalse($product->is_taxable);
    }

    #[Test]
    public function the_flags_must_be_true_or_false(): void
    {
        $this->actingAs($this->admin())
            ->post(route('acp.commerce.products.store'), ['name' => 'Odd', 'slug' => 'odd', 'requires_shipping' => 'maybe'])
            ->assertSessionHasErrors('requires_shipping');

        $this->assertSame(0, Product::where('slug', 'odd')->count());
    }
}
