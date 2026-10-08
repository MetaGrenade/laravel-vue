<?php

namespace Database\Factories;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRate>
 */
class ShippingRateFactory extends Factory
{
    protected $model = ShippingRate::class;

    public function definition(): array
    {
        return [
            'shipping_zone_id' => ShippingZone::factory(),
            'name' => 'Standard',
            'amount' => '5.00',
            'min_subtotal' => null,
            'max_subtotal' => null,
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
