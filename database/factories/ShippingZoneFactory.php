<?php

namespace Database\Factories;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingZone>
 */
class ShippingZoneFactory extends Factory
{
    protected $model = ShippingZone::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)).' zone',
            'countries' => ['GB'],
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * @param  list<string>  $countries
     */
    public function serving(array $countries): static
    {
        return $this->state(['countries' => $countries]);
    }

    public function everywhereElse(): static
    {
        return $this->state(['countries' => ['*']]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Give the zone a flat rate.
     */
    public function withRate(string $name, string $amount, ?string $min = null, ?string $max = null): static
    {
        return $this->afterCreating(function (ShippingZone $zone) use ($name, $amount, $min, $max) {
            ShippingRate::factory()->for($zone, 'zone')->create([
                'name' => $name,
                'amount' => $amount,
                'min_subtotal' => $min,
                'max_subtotal' => $max,
                'position' => $zone->rates()->count(),
            ]);
        });
    }
}
