<?php

namespace App\Http\Requests\Admin;

use App\Models\Entitlement;

/**
 * What one subscription plan gives: for a limit, how much the plan gives (not per unit: a plan is held once).
 */
class SubscriptionPlanEntitlementsRequest extends EntitlementListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function missingAmount(Entitlement $entitlement): string
    {
        return "Say how much the plan gives of {$entitlement->label}.";
    }
}
