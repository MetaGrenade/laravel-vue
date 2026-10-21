<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TebexPackageEntitlementsRequest;
use App\Models\Entitlement;
use App\Models\TebexPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * What buying a Tebex package gives the buyer's account in the shop. Tebex owns the catalogue and knows nothing of this: it is
 * kept apart, so a synchronisation never touches it.
 *
 * It decides what a purchase made from now on gives. Orders already paid keep what they were given.
 */
class TebexPackageEntitlementController extends Controller
{
    public function update(TebexPackageEntitlementsRequest $request, TebexPackage $package): RedirectResponse
    {
        $rows = collect($request->validated('entitlements'));
        $entitlements = Entitlement::query()->whereIn('id', $rows->pluck('id')->all())->get()->keyBy('id');

        DB::transaction(function () use ($package, $rows, $entitlements) {
            $package->entitlementLinks()->whereNotIn('entitlement_id', $rows->pluck('id')->all())->delete();

            foreach ($rows as $row) {
                $package->entitlementLinks()->updateOrCreate(
                    ['entitlement_id' => $row['id']],
                    ['value' => $entitlements[$row['id']]->isLimit() ? (int) $row['value'] : null],
                );
            }
        });

        return back()->with('success', $rows->isEmpty() ? "{$package->name} no longer gives anything." : "What {$package->name} gives was saved.");
    }
}
