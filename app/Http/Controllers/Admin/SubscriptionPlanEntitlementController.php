<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionPlanEntitlementsRequest;
use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * What being on a subscription plan gives the account. Unlike a package, it reaches everyone on the plan at once: the
 * plans of an account's live subscriptions are what it holds, so this is also what the people already on the plan are given
 * (or lose) from now on.
 */
class SubscriptionPlanEntitlementController extends Controller
{
    public function update(SubscriptionPlanEntitlementsRequest $request, SubscriptionPlan $plan): RedirectResponse
    {
        $rows = collect($request->validated('entitlements'));
        $entitlements = Entitlement::query()->whereIn('id', $rows->pluck('id')->all())->get()->keyBy('id');

        DB::transaction(function () use ($plan, $rows, $entitlements) {
            $plan->entitlementLinks()->whereNotIn('entitlement_id', $rows->pluck('id')->all())->delete();

            foreach ($rows as $row) {
                $plan->entitlementLinks()->updateOrCreate(
                    ['entitlement_id' => $row['id']],
                    ['value' => $entitlements[$row['id']]->isLimit() ? (int) $row['value'] : null],
                );
            }
        });

        return back()->with('success', $rows->isEmpty() ? "{$plan->name} no longer gives anything." : "What {$plan->name} gives was saved.");
    }
}
