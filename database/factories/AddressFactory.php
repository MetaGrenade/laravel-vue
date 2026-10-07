<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            'owner_type' => (new User)->getMorphClass(),
            'owner_id' => User::factory(),
            'name' => fake()->name(),
            'line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'region' => null,
            'postal_code' => fake()->postcode(),
            'country' => 'GB',
            'is_default' => false,
        ];
    }

    public function forOwner(User $user): static
    {
        return $this->state(fn () => [
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->id,
        ]);
    }

    public function inCountry(string $country, ?string $region = null): static
    {
        return $this->state(fn () => ['country' => $country, 'region' => $region]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
