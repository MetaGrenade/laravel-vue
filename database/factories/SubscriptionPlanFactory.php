<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word()).' Plan';

        return [
            'name' => $name,
            'slug' => Str::slug($name.' '.fake()->unique()->word()),
            'stripe_price_id' => 'price_'.Str::upper(Str::random(20)),
            'interval' => 'month',
            'price' => fake()->numberBetween(500, 5000),
            'currency' => 'USD',
            'description' => fake()->sentence(),
            'features' => [fake()->sentence(), fake()->sentence()],
            'is_active' => true,
        ];
    }
}
