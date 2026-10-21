<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EntitlementRequest;
use App\Models\Entitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The things a purchase can give an account (a feature switched on, a limit raised). Which packages give which is set on the
 * Tebex page, next to the packages; this is the list they choose from.
 */
class EntitlementController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('acp/CommerceEntitlements', [
            'entitlements' => Entitlement::query()
                ->withCount([
                    'packageLinks as packages_count',
                    'grants as grants_count' => fn ($query) => $query->active(),
                ])
                ->orderBy('label')
                ->get()
                ->map(fn (Entitlement $entitlement) => [
                    'id' => $entitlement->id,
                    'key' => $entitlement->key,
                    'label' => $entitlement->label,
                    'description' => $entitlement->description,
                    'type' => $entitlement->type->value,
                    'type_label' => $entitlement->type->label(),
                    'packages_count' => (int) $entitlement->getAttribute('packages_count'),
                    // Grants that count now, not people: one account holds one per line it bought.
                    'grants_count' => (int) $entitlement->getAttribute('grants_count'),
                ])
                ->values()
                ->all(),
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
        ]);
    }

    public function store(EntitlementRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Entitlement::create([
            'key' => $validated['key'],
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
        ]);

        return back()->with('success', 'Entitlement added.');
    }

    /**
     * Only the label and description: the key and type are what code and grants rely on.
     */
    public function update(EntitlementRequest $request, Entitlement $entitlement): RedirectResponse
    {
        $validated = $request->validated();

        $entitlement->update([
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('success', 'Entitlement saved.');
    }

    /**
     * Refused while anyone holds it: taking it away from people who paid for it is not something a delete should do quietly.
     * What is left (packages that gave it, grants already taken back) goes with it.
     */
    public function destroy(Entitlement $entitlement): RedirectResponse
    {
        $held = $entitlement->grants()->active()->count();

        if ($held > 0) {
            return back()->with('error', "{$entitlement->label} is held through {$held} ".($held === 1 ? 'purchase' : 'purchases').' and cannot be deleted. Take it off the packages that give it, and it stops being given from now on.');
        }

        $entitlement->delete();

        return back()->with('success', 'Entitlement deleted.');
    }
}
