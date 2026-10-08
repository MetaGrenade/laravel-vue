<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\StockAdjustRequest;
use App\Http\Requests\Admin\Catalogue\StockTrackRequest;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Catalogue\StockAdjuster;
use Illuminate\Http\RedirectResponse;

/**
 * Stock of a product or one of its variants. Everything goes through {@see StockAdjuster}, which
 * writes each change to the movement ledger.
 */
class StockController extends Controller
{
    public function __construct(private readonly StockAdjuster $stock) {}

    public function track(StockTrackRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();

        $variant = ($validated['product_variant_id'] ?? null)
            ? ProductVariant::query()->where('product_id', $product->id)->findOrFail($validated['product_variant_id'])
            : null;

        try {
            $this->stock->track($product, $variant, (int) $validated['quantity'], $request->boolean('allow_backorder'), $request->user());
        } catch (CatalogueException $exception) {
            return $this->refuse($exception);
        }

        return back()->with('success', 'Stock is now tracked.');
    }

    public function update(StockAdjustRequest $request, InventoryItem $item): RedirectResponse
    {
        $validated = $request->validated();

        try {
            if (($validated['mode'] ?? null) !== null && ($validated['quantity'] ?? null) !== null) {
                $quantity = (int) $validated['quantity'];
                $note = $validated['note'] ?? null;

                $validated['mode'] === 'set'
                    ? $this->stock->set($item, $quantity, $note, $request->user())
                    : $this->stock->change($item, $quantity, $note, $request->user());
            }
        } catch (CatalogueException $exception) {
            return $this->refuse($exception);
        }

        if ($request->has('allow_backorder')) {
            $item->update(['allow_backorder' => $request->boolean('allow_backorder')]);
        }

        return back()->with('success', 'Stock updated.');
    }

    /**
     * Stop tracking: the item can always be bought, and its stock history is deleted with it.
     */
    public function destroy(InventoryItem $item): RedirectResponse
    {
        $this->stock->untrack($item);

        return back()->with('success', 'Stock is no longer tracked.');
    }

    private function refuse(CatalogueException $exception): RedirectResponse
    {
        return back()->withErrors([$exception->field ?? 'quantity' => $exception->getMessage()]);
    }
}
