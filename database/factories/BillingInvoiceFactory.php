<?php

namespace Database\Factories;

use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingInvoice>
 */
class BillingInvoiceFactory extends Factory
{
    protected $model = BillingInvoice::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'stripe_id' => 'in_'.Str::lower(Str::random(26)),
            'stripe_customer_id' => 'cus_'.Str::lower(Str::random(14)),
            'status' => fake()->randomElement(['paid', 'open', 'draft', 'uncollectible']),
            'currency' => 'USD',
            'subtotal' => fake()->numberBetween(500, 5000),
            'tax' => fake()->numberBetween(0, 500),
            'total' => fake()->numberBetween(500, 5500),
            'due_at' => now()->addDays(fake()->numberBetween(0, 10)),
            'paid_at' => now(),
            'data' => ['lines' => []],
        ];
    }
}
