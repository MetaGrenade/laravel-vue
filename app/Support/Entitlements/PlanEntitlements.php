<?php

namespace App\Support\Entitlements;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Subscription;

/**
 * What an owner's subscription plans give them.
 *
 * Nothing is written when someone subscribes (a purchase writes grants, see {@see OrderEntitlements}). The owner holds what
 * the plans of their **live** subscriptions give, worked out each time it is asked, so there is nothing to keep in step: a
 * plan changed by staff, a subscription swapped to another plan, cancelled, ended or let lapse reaches the account the next
 * time anything asks.
 *
 * A subscription is live as Cashier says it is ({@see Subscription::valid()}): paid and running, on a trial, or cancelled
 * but still inside the period that was paid for. One that is incomplete, unpaid or past due is not (Cashier's defaults),
 * and neither is one that has ended. It is matched to its plan by Stripe price, like the billing page and the dashboard, so a
 * subscription to a price no plan carries gives nothing. A plan switched off on the pricing page still serves the people
 * already on it.
 */
class PlanEntitlements
{
    /**
     * What the owner's live subscriptions give: one link per entitlement per subscription, so two live subscriptions to
     * the same plan give it twice (limits add up, as they do across purchases).
     *
     * @return Collection<int, SubscriptionPlanEntitlement>
     */
    public function gives(Model $owner): Collection
    {
        // A team will be billed the same way in 1.1: widen this when it is.
        if (! $owner instanceof User) {
            return new Collection;
        }

        // Asked of the query, not the `subscriptions` relation, which would keep what it was first told.
        $live = Subscription::query()
            ->where($owner->getForeignKey(), $owner->getKey())
            ->get()
            ->filter(fn (Subscription $subscription) => $subscription->valid());

        if ($live->isEmpty()) {
            return new Collection;
        }

        $plans = SubscriptionPlan::query()
            ->whereIn('stripe_price_id', $live->pluck('stripe_price')->unique()->all())
            ->with('entitlementLinks.entitlement')
            ->get()
            ->keyBy('stripe_price_id');

        $links = new Collection;

        foreach ($live as $subscription) {
            $plan = $plans->get($subscription->stripe_price);

            if ($plan === null) {
                continue;
            }

            foreach ($plan->entitlementLinks as $link) {
                $links->push($link);
            }
        }

        return $links;
    }
}
