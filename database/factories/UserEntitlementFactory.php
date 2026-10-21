<?php

namespace Database\Factories;

use App\Models\Entitlement;
use App\Models\UserEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserEntitlement>
 */
class UserEntitlementFactory extends Factory
{
    protected $model = UserEntitlement::class;

    public function definition(): array
    {
        return [
            'entitlement_id' => Entitlement::factory(),
            'source' => 'admin',
        ];
    }

    public function revoked(string $reason = UserEntitlement::BY_REFUND): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subHour(), 'revoked_reason' => $reason]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
