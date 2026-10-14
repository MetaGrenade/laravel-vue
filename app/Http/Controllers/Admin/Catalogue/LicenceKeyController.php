<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Digital\LicenceKeys;
use App\Support\Commerce\OrderLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The pool of licence keys behind a product's (or a variant's) stock, managed from the product's page.
 * Everything goes through {@see LicenceKeys}, which keeps the stock count in step with the pool.
 */
class LicenceKeyController extends Controller
{
    public function __construct(
        private readonly LicenceKeys $keys,
        private readonly OrderLifecycle $lifecycle,
    ) {}

    /**
     * Start delivering keys from a product's stock, or from one of its variants'.
     */
    public function enable(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'product_variant_id' => ['nullable', 'integer'],
        ]);

        $variant = ($validated['product_variant_id'] ?? null)
            ? ProductVariant::query()->where('product_id', $product->id)->findOrFail($validated['product_variant_id'])
            : null;

        try {
            $this->keys->enable($product, $variant, $request->user());
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Licence keys are on. Add the keys to sell.');
    }

    public function disable(InventoryItem $item): RedirectResponse
    {
        try {
            $this->keys->disable($item);
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Licence keys are off for this stock.');
    }

    /**
     * Add keys, one per line. Paid orders that were waiting for keys get them straight away.
     */
    public function store(Request $request, InventoryItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'keys' => ['required', 'string', 'max:300000'],
        ], [
            'keys.required' => 'Enter at least one licence key, one on each line.',
            'keys.max' => 'That is too much to add at once. Add the keys in several smaller batches.',
        ]);

        try {
            $result = $this->keys->add($item, $validated['keys'], $request->user());
        } catch (CatalogueException $exception) {
            return back()->withErrors(['keys' => $exception->getMessage()]);
        }

        $message = $result['added'].' licence '.Str::plural('key', $result['added']).' added';

        if ($result['duplicates'] > 0) {
            $message .= ", {$result['duplicates']} skipped because ".($result['duplicates'] === 1 ? 'it was' : 'they were').' already in the pool';
        }

        if ($result['added'] > 0 && ($issued = $this->lifecycle->issueWaitingKeys($item)) > 0) {
            $message .= ", and {$issued} issued to ".($issued === 1 ? 'an order' : 'orders').' that had been waiting';
        }

        return back()->with('success', $message.'.');
    }

    /**
     * Delete keys that are still in the pool.
     */
    public function destroy(Request $request, InventoryItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        try {
            $deleted = $this->keys->remove($item, array_map('intval', $validated['ids']), $request->user());
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $deleted === 0 ? 'Nothing was deleted: those keys are no longer in the pool.' : $deleted.' licence '.Str::plural('key', $deleted).' deleted.');
    }
}
