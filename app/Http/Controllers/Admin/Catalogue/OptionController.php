<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\OptionRequest;
use App\Http\Requests\Admin\Catalogue\OptionValueRequest;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Catalogue\OptionEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * A product's options (Size, Colour) and the values each can take. Variants are made from them.
 */
class OptionController extends Controller
{
    public function __construct(private readonly OptionEditor $editor) {}

    public function store(OptionRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $product) {
            $option = $product->options()->create([
                'name' => $validated['name'],
                'display_name' => $validated['display_name'],
                'position' => (int) $product->options()->max('position') + 1,
            ]);

            foreach (array_values($validated['values'] ?? []) as $position => $value) {
                $option->values()->create(['value' => $value, 'position' => $position]);
            }
        });

        return back()->with('success', 'Option added.');
    }

    public function update(OptionRequest $request, ProductOption $option): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $option) {
            $this->editor->renameOption($option, $validated['name']);
            $option->update(['display_name' => $validated['display_name']]);
        });

        return back()->with('success', 'Option saved.');
    }

    public function destroy(ProductOption $option): RedirectResponse
    {
        try {
            $this->editor->deleteOption($option);
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Option deleted.');
    }

    public function storeValue(OptionValueRequest $request, ProductOption $option): RedirectResponse
    {
        $option->values()->create([
            'value' => $request->validated('value'),
            'position' => (int) $option->values()->max('position') + 1,
        ]);

        return back()->with('success', 'Value added.');
    }

    public function updateValue(OptionValueRequest $request, ProductOptionValue $value): RedirectResponse
    {
        $this->editor->renameValue($value, $request->validated('value'));

        return back()->with('success', 'Value saved.');
    }

    public function destroyValue(ProductOptionValue $value): RedirectResponse
    {
        try {
            $this->editor->deleteValue($value);
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Value deleted.');
    }
}
