<?php

namespace App\Http\Requests\Admin;

use App\Models\Entitlement;

/**
 * What one Tebex package gives: for a limit, the amount one unit of the package adds.
 */
class TebexPackageEntitlementsRequest extends EntitlementListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function missingAmount(Entitlement $entitlement): string
    {
        return "Say how much one unit adds to {$entitlement->label}.";
    }
}
