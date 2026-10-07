<?php

namespace Database\Factories;

use App\Models\Price;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    protected $model = Price::class;

    public function definition(): array
    {
        return [
            'priceable_type' => (new Product)->getMorphClass(),
            'priceable_id' => Product::factory(),
            'currency' => config('commerce.currency'),
            'amount' => fake()->randomFloat(2, 5, 200),
            'compare_at_amount' => null,
            'is_active' => true,
        ];
    }
}
