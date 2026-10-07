<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A product that is not shipped (a download, a licence): no address or shipping charge.
     */
    public function digital(): static
    {
        return $this->state(['requires_shipping' => false]);
    }

    public function untaxed(): static
    {
        return $this->state(['is_taxable' => false]);
    }

    /**
     * Give the product a price in the given currency.
     */
    public function priced(string $amount, ?string $currency = null): static
    {
        return $this->afterCreating(function (Product $product) use ($amount, $currency) {
            Price::factory()->for($product, 'priceable')->create([
                'amount' => $amount,
                'currency' => $currency ?? config('commerce.currency'),
            ]);
        });
    }

    /**
     * Track stock for the product itself (not a variant).
     */
    public function stocked(int $quantity, bool $allowBackorder = false): static
    {
        return $this->afterCreating(function (Product $product) use ($quantity, $allowBackorder) {
            InventoryItem::factory()->create([
                'product_id' => $product->id,
                'product_variant_id' => null,
                'quantity' => $quantity,
                'allow_backorder' => $allowBackorder,
            ]);
        });
    }
}
