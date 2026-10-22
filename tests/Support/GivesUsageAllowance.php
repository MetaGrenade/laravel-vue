<?php

namespace Tests\Support;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\User;
use Laravel\Cashier\Subscription;

/**
 * For tests of metered usage: an account whose subscription plan gives a monthly allowance of a meter.
 */
trait GivesUsageAllowance
{
    private int $usagePlans = 0;

    /**
     * Put the account on a live plan that gives `api-calls` as a limit of $amount a month.
     */
    private function onAPlanGiving(User $user, int $amount, string $entitlement = 'api-calls'): void
    {
        $limit = Entitlement::query()->where('key', $entitlement)->first()
            ?? Entitlement::factory()->limit()->create(['key' => $entitlement, 'label' => ucfirst($entitlement)]);

        $plan = SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_usage_'.(++$this->usagePlans)]);
        SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $limit->id, 'value' => $amount]);

        Subscription::create([
            'user_id' => $user->id,
            'type' => config('billing.subscription_name', 'default'),
            'stripe_id' => 'sub_usage_'.$this->usagePlans,
            'stripe_status' => 'active',
            'stripe_price' => $plan->stripe_price_id,
            'quantity' => 1,
        ]);
    }
}
