<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShippingZoneRequest;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Support\Commerce\Countries;
use App\Support\Commerce\ShippingCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the shop ships to. Rates (what it costs) are managed by
 * {@see ShippingRateController}.
 */
class ShippingZoneController extends Controller
{
    public function index(Request $request, ShippingCalculator $shipping): Response
    {
        $user = $request->user();

        $zones = ShippingZone::query()
            ->with('rates')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ShippingZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'countries' => array_values($zone->countries ?? []),
                'position' => $zone->position,
                'is_active' => $zone->is_active,
                'rates' => $zone->rates->map(fn (ShippingRate $rate) => [
                    'id' => $rate->id,
                    'name' => $rate->name,
                    'description' => $rate->description,
                    'amount' => $rate->amount,
                    'min_subtotal' => $rate->min_subtotal,
                    'max_subtotal' => $rate->max_subtotal,
                    'position' => $rate->position,
                    'is_active' => $rate->is_active,
                ])->values(),
            ])
            ->values();

        return Inertia::render('acp/CommerceShipping', [
            'zones' => $zones,
            // False until an active zone exists: until then orders ship anywhere for free.
            'configured' => $shipping->isConfigured(),
            'currency' => strtoupper((string) config('commerce.currency', 'USD')),
            'countries' => Countries::codes(),
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
        ]);
    }

    public function store(ShippingZoneRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        ShippingZone::create([
            'name' => $validated['name'],
            'countries' => $validated['countries'],
            'position' => $validated['position'] ?? ((int) ShippingZone::query()->max('position') + 1),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return back()->with('success', 'Shipping zone created.');
    }

    public function update(ShippingZoneRequest $request, ShippingZone $zone): RedirectResponse
    {
        $validated = $request->validated();

        $zone->update([
            'name' => $validated['name'],
            'countries' => $validated['countries'],
            'position' => $validated['position'] ?? $zone->position,
            'is_active' => (bool) ($validated['is_active'] ?? $zone->is_active),
        ]);

        return back()->with('success', 'Shipping zone updated.');
    }

    /**
     * Deleting a zone deletes its rates. Orders already placed keep the shipping
     * method name and price they were charged.
     */
    public function destroy(ShippingZone $zone): RedirectResponse
    {
        $zone->delete();

        return back()->with('success', 'Shipping zone deleted.');
    }
}
