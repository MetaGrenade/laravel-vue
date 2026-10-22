<?php

namespace Database\Seeders;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = config('billing.plans', []);

        // What the plans can give. An entitlement that exists is left as staff made it: its key and type are what code and
        // grants rely on.
        foreach (config('billing.entitlements', []) as $key => $definition) {
            Entitlement::firstOrCreate(['key' => $key], [
                'label' => $definition['label'],
                'description' => $definition['description'] ?? null,
                'type' => $definition['type'],
            ]);
        }

        foreach ($plans as $plan) {
            if (! isset($plan['stripe_price']) || empty($plan['stripe_price'])) {
                continue;
            }

            $model = SubscriptionPlan::updateOrCreate(
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
                    // Only when the config says: a plan whose trial staff set is not reset to none by seeding again.
                    ...(array_key_exists('trial_days', $plan) ? ['trial_days' => $plan['trial_days']] : []),
                ]
            );

            $this->giveEntitlements($model, Arr::get($plan, 'entitlements', []));
        }
    }

    /**
     * Make the plan give what it is configured to. Adds and updates only: what staff added to the plan since is not removed.
     *
     * @param  array<string, int|bool>  $gives  Key => `true` for a feature, an amount for a limit.
     */
    private function giveEntitlements(SubscriptionPlan $plan, array $gives): void
    {
        foreach ($gives as $key => $value) {
            $entitlement = Entitlement::query()->where('key', $key)->first();

            if ($entitlement === null) {
                continue;
            }

            $plan->entitlementLinks()->updateOrCreate(
                ['entitlement_id' => $entitlement->id],
                ['value' => $entitlement->isLimit() ? max(1, (int) $value) : null],
            );
        }
    }
}
