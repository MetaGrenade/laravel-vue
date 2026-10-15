<?php

namespace Database\Factories;

use App\Models\TebexCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TebexCategory>
 */
class TebexCategoryFactory extends Factory
{
    protected $model = TebexCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'tebex_id' => fake()->unique()->numberBetween(1000, 9_999_999),
            'name' => ucfirst($name),
            'slug' => str($name)->slug()->toString(),
            'sort_order' => 1,
            'display_type' => 'list',
        ];
    }

    public function removed(): static
    {
        return $this->state(['removed_at' => now()]);
    }
}
