<?php

namespace Database\Factories;

use App\Models\TebexCategory;
use App\Models\TebexPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TebexPackage>
 */
class TebexPackageFactory extends Factory
{
    protected $model = TebexPackage::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'tebex_id' => fake()->unique()->numberBetween(1000, 9_999_999),
            'ident' => 'ident-'.fake()->unique()->numerify('########'),
            'tebex_category_id' => TebexCategory::factory(),
            'name' => ucfirst($name),
            'slug' => str($name)->slug()->toString(),
            'type' => TebexPackage::SINGLE,
            'base_price' => '10.00',
            'sales_tax' => '0.00',
            'total_price' => '10.00',
            'currency' => 'USD',
            'discount' => '0.00',
            'sort_order' => 1,
        ];
    }

    public function priced(string $price): static
    {
        return $this->state(['base_price' => $price, 'total_price' => $price]);
    }

    public function subscription(): static
    {
        return $this->state(['type' => TebexPackage::SUBSCRIPTION]);
    }

    public function removed(): static
    {
        return $this->state(['removed_at' => now()]);
    }
}
