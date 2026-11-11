<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `Product::optionValues()` is every value of every option of a product. It was declared to return a `HasMany` and returned a
 * `HasManyThrough`, which PHP refuses at the first call ("must be of type HasMany, HasManyThrough returned"). Nothing called it yet.
 */
class ProductOptionValuesRelationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_product_can_list_every_value_of_all_its_options(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();

        $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Size', 'display_name' => 'Size', 'position' => 1]);
        $colour = ProductOption::create(['product_id' => $product->id, 'name' => 'Colour', 'display_name' => 'Colour', 'position' => 2]);
        $elsewhere = ProductOption::create(['product_id' => $other->id, 'name' => 'Size', 'display_name' => 'Size', 'position' => 1]);

        foreach (['S', 'M'] as $position => $value) {
            $size->values()->create(['value' => $value, 'position' => $position]);
        }
        $colour->values()->create(['value' => 'Red', 'position' => 0]);
        $elsewhere->values()->create(['value' => 'XL', 'position' => 0]);

        $values = $product->optionValues()->orderBy('product_option_values.id')->pluck('value')->all();

        $this->assertSame(['S', 'M', 'Red'], $values, 'only this product\'s values');
        $this->assertCount(3, $product->optionValues);
    }

    #[Test]
    public function the_values_can_be_loaded_together_with_the_products(): void
    {
        $product = Product::factory()->create();
        $option = ProductOption::create(['product_id' => $product->id, 'name' => 'Size', 'display_name' => 'Size']);
        $option->values()->create(['value' => 'S']);

        $loaded = Product::query()->with('optionValues')->findOrFail($product->id);

        $this->assertTrue($loaded->relationLoaded('optionValues'));
        $this->assertSame(['S'], $loaded->optionValues->pluck('value')->all());
    }
}
