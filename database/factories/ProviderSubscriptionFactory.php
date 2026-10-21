<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\ProviderSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProviderSubscription>
 */
class ProviderSubscriptionFactory extends Factory
{
    protected $model = ProviderSubscription::class;

    public function definition(): array
    {
        return [
            'provider' => 'tebex',
            'reference' => 'tbx-r-'.Str::lower(Str::random(10)),
            'package_name' => 'MVP (monthly)',
            'status' => SubscriptionStatus::Active,
            'amount' => '4.50',
            'currency' => 'USD',
            'started_at' => now()->subDays(10),
            'next_payment_at' => now()->addDays(20),
        ];
    }

    public function ended(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()->subDay(), 'ended_at' => now()->subDay(), 'next_payment_at' => null]);
    }
}
