<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\VariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Catalogue\CatalogueRemover;
use App\Support\Commerce\Catalogue\VariantGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class VariantController extends Controller
{
    public function __construct(
        private readonly VariantGenerator $generator,
        private readonly CatalogueRemover $remover,
    ) {}

    public function store(VariantRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $product) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $first = ! $product->variants()->exists();
            $default = $first || (bool) ($validated['is_default'] ?? false);

            $variant = $product->variants()->create([
                'name' => $validated['name'],
                'sku' => $validated['sku'] ?? null,
                'option_values' => $this->optionValues($validated),
                'is_default' => $default,
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);

            if ($default) {
                $this->makeOnlyDefault($variant);
            }
        });

        return back()->with('success', 'Variant added.');
    }

    public function update(VariantRequest $request, ProductVariant $variant): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $variant) {
            Product::query()->whereKey($variant->product_id)->lockForUpdate()->first();

            $variant->update([
                'name' => $validated['name'],
                'sku' => $validated['sku'] ?? null,
                'option_values' => $this->optionValues($validated),
                'is_default' => (bool) ($validated['is_default'] ?? $variant->is_default),
                'is_active' => (bool) ($validated['is_active'] ?? $variant->is_active),
            ]);

            if ($variant->is_default) {
                $this->makeOnlyDefault($variant);
            }
        });

        return back()->with('success', 'Variant saved.');
    }

    /**
     * Make a variant for every combination of the product's options that does not have one.
     */
    public function generate(Product $product): RedirectResponse
    {
        try {
            $created = $this->generator->generate($product);
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $created === 0
            ? back()->with('info', 'Every combination already has a variant.')
            : back()->with('success', $created.' '.($created === 1 ? 'variant' : 'variants').' created. Give them prices and stock.');
    }

    public function destroy(ProductVariant $variant): RedirectResponse
    {
        try {
            $this->remover->deleteVariant($variant);
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Variant deleted.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>|null
     */
    private function optionValues(array $validated): ?array
    {
        $values = array_map('strval', (array) ($validated['option_values'] ?? []));

        return $values === [] ? null : $values;
    }

    private function makeOnlyDefault(ProductVariant $variant): void
    {
        ProductVariant::query()
            ->where('product_id', $variant->product_id)
            ->whereKeyNot($variant->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
