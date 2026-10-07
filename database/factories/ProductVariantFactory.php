<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => ucfirst(fake()->word()),
            'sku' => Str::upper(Str::random(8)),
            'is_default' => false,
        ];
    }

    public function priced(string $amount, ?string $currency = null): static
    {
        return $this->afterCreating(function (ProductVariant $variant) use ($amount, $currency) {
            Price::factory()->for($variant, 'priceable')->create([
                'amount' => $amount,
                'currency' => $currency ?? config('commerce.currency'),
            ]);
        });
    }

    public function stocked(int $quantity, bool $allowBackorder = false): static
    {
        return $this->afterCreating(function (ProductVariant $variant) use ($quantity, $allowBackorder) {
            InventoryItem::factory()->create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'allow_backorder' => $allowBackorder,
            ]);
        });
    }
}
