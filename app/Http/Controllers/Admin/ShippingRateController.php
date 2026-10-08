<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShippingRateRequest;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Support\Commerce\Money;
use Illuminate\Http\RedirectResponse;

/**
 * The shipping methods a zone offers, and what each costs.
 */
class ShippingRateController extends Controller
{
    public function store(ShippingRateRequest $request, ShippingZone $zone): RedirectResponse
    {
        $validated = $request->validated();

        $zone->rates()->create([
            ...$this->attributes($validated),
            'position' => $validated['position'] ?? ((int) $zone->rates()->max('position') + 1),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return back()->with('success', 'Shipping rate added.');
    }

    public function update(ShippingRateRequest $request, ShippingRate $rate): RedirectResponse
    {
        $validated = $request->validated();

        $rate->update([
            ...$this->attributes($validated),
            'position' => $validated['position'] ?? $rate->position,
            'is_active' => (bool) ($validated['is_active'] ?? $rate->is_active),
        ]);

        return back()->with('success', 'Shipping rate updated.');
    }

    public function destroy(ShippingRate $rate): RedirectResponse
    {
        $rate->delete();

        return back()->with('success', 'Shipping rate deleted.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $currency = (string) config('commerce.currency', 'USD');
        $money = fn (mixed $value): ?string => blank($value) ? null : Money::parse((string) $value, $currency)->toDecimal();

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'amount' => $money($validated['amount']),
            'min_subtotal' => $money($validated['min_subtotal'] ?? null),
            'max_subtotal' => $money($validated['max_subtotal'] ?? null),
        ];
    }
}
