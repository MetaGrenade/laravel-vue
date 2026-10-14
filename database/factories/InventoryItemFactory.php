<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'quantity' => 10,
            'allow_backorder' => false,
            'delivers_keys' => false,
        ];
    }

    /**
     * Stock that follows a pool of licence keys: nothing in stock until keys are added.
     */
    public function keyed(): static
    {
        return $this->state(['quantity' => 0, 'allow_backorder' => false, 'delivers_keys' => true]);
    }
}
