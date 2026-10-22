<?php

namespace App\Support\Billing;

use App\Models\SubscriptionPlan;

/**
 * A subscription plan as the pricing page and the billing settings show it. Load `entitlementLinks.entitlement` first.
 */
final class PlanPresenter
{
    /**
     * @return array{id: int, name: string, slug: string, price: int, currency: string, interval: string, features: list<string>, entitlements: list<array{key: string, label: string, type: string, value: int|null}>, stripe_price_id: string, trial_days: int|null}
     */
    public static function forPage(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            // What staff wrote about it, as written.
            'features' => $plan->features ?? [],
            // What it really gives: the features it switches on and the limits it raises.
            'entitlements' => self::entitlements($plan),
            'stripe_price_id' => $plan->stripe_price_id,
            // Free days a new customer gets before the first payment (whether this customer is eligible is for the page to say).
            'trial_days' => $plan->trial_days,
        ];
    }

    /**
     * What the plan gives, by name.
     *
     * @return list<array{key: string, label: string, type: string, value: int|null}>
     */
    public static function entitlements(SubscriptionPlan $plan): array
    {
        return $plan->entitlementLinks
            ->sortBy(fn ($link) => mb_strtolower($link->entitlement->label))
            ->map(fn ($link) => [
                'key' => $link->entitlement->key,
                'label' => $link->entitlement->label,
                'type' => $link->entitlement->type->value,
                'value' => $link->value,
            ])
            ->values()
            ->all();
    }
}
