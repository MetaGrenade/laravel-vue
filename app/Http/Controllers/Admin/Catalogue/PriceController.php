<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\PriceRequest;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\Money;
use App\Support\Commerce\PriceResolver;
use Illuminate\Http\RedirectResponse;

/**
 * Prices of a product or of one of its variants. The shop sells in one currency, so a price is
 * always created in it; the one active price in that currency is what a shopper is charged.
 */
class PriceController extends Controller
{
    public function __construct(private readonly PriceResolver $prices) {}

    public function storeForProduct(PriceRequest $request, Product $product): RedirectResponse
    {
        return $this->create($request, $product);
    }

    public function storeForVariant(PriceRequest $request, ProductVariant $variant): RedirectResponse
    {
        return $this->create($request, $variant);
    }

    public function update(PriceRequest $request, Price $price): RedirectResponse
    {
        $validated = $request->validated();

        $price->update([
            'amount' => Money::parse($validated['amount'], $price->currency)->toDecimal(),
            'compare_at_amount' => filled($validated['compare_at_amount'] ?? null)
                ? Money::parse($validated['compare_at_amount'], $price->currency)->toDecimal()
                : null,
            'is_active' => (bool) ($validated['is_active'] ?? $price->is_active),
        ]);

        return back()->with('success', 'Price saved.');
    }

    public function destroy(Price $price): RedirectResponse
    {
        $price->delete();

        return back()->with('success', 'Price deleted.');
    }

    private function create(PriceRequest $request, Product|ProductVariant $owner): RedirectResponse
    {
        $validated = $request->validated();
        $currency = $this->prices->currency();

        $owner->prices()->create([
            'currency' => $currency,
            'amount' => Money::parse($validated['amount'], $currency)->toDecimal(),
            'compare_at_amount' => filled($validated['compare_at_amount'] ?? null)
                ? Money::parse($validated['compare_at_amount'], $currency)->toDecimal()
                : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return back()->with('success', 'Price added.');
    }
}
