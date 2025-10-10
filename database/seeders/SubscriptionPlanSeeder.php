<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = config('billing.plans', []);

        foreach ($plans as $plan) {
            if (! isset($plan['stripe_price']) || empty($plan['stripe_price'])) {
                continue;
            }

            SubscriptionPlan::updateOrCreate(
                ['stripe_price_id' => $plan['stripe_price']],
                [
                    'name' => $plan['name'],
                    'slug' => $plan['slug'],
                    'price' => Arr::get($plan, 'price', 0),
                    'interval' => Arr::get($plan, 'interval', 'month'),
                    'currency' => strtoupper(Arr::get($plan, 'currency', config('billing.currency', 'USD'))),
                    'description' => Arr::get($plan, 'description'),
                    'features' => Arr::get($plan, 'features', []),
                    'is_active' => Arr::get($plan, 'is_active', true),
                ]
            );
        }
    }
}
