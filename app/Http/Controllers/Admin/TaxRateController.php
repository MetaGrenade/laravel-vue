<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TaxRateRequest;
use App\Models\TaxRate;
use App\Support\Commerce\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tax table. Prices are tax-exclusive: these rates are added at checkout.
 */
class TaxRateController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('acp/CommerceTax', [
            'rates' => TaxRate::query()
                ->orderBy('country')
                ->orderBy('region')
                ->orderBy('id')
                ->get()
                ->map(fn (TaxRate $rate) => [
                    'id' => $rate->id,
                    'name' => $rate->name,
                    'country' => $rate->country,
                    'region' => $rate->region,
                    'rate' => $this->trim((string) $rate->rate),
                    'applies_to_shipping' => $rate->applies_to_shipping,
                    'is_active' => $rate->is_active,
                ])
                ->values(),
            'countries' => Countries::codes(),
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
        ]);
    }

    public function store(TaxRateRequest $request): RedirectResponse
    {
        TaxRate::create($this->attributes($request->validated()) + ['is_active' => (bool) ($request->validated()['is_active'] ?? true)]);

        return back()->with('success', 'Tax rate added.');
    }

    public function update(TaxRateRequest $request, TaxRate $taxRate): RedirectResponse
    {
        $validated = $request->validated();

        $taxRate->update($this->attributes($validated) + ['is_active' => (bool) ($validated['is_active'] ?? $taxRate->is_active)]);

        return back()->with('success', 'Tax rate updated.');
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        $taxRate->delete();

        return back()->with('success', 'Tax rate deleted.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'country' => $validated['country'],
            'region' => filled($validated['region'] ?? null) ? trim((string) $validated['region']) : null,
            'rate' => $validated['rate'],
            'applies_to_shipping' => (bool) ($validated['applies_to_shipping'] ?? true),
        ];
    }

    /**
     * "20.0000" reads better as "20" and "8.8750" as "8.875".
     */
    private function trim(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
