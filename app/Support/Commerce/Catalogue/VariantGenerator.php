<?php

namespace App\Support\Commerce\Catalogue;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds a product's variants from its options: one variant for every combination of values
 * (a size and a colour with three values each make nine). Combinations that already have a
 * variant are left alone, so it can be run again after adding a value.
 */
class VariantGenerator
{
    /** More than this is almost certainly a mistake, and would be unmanageable to price. */
    public const LIMIT = 100;

    /**
     * Every combination of the product's option values, as maps of option name to value.
     *
     * @return list<array<string, string>>
     */
    public function combinations(Product $product): array
    {
        /** @var Collection<int, ProductOption> $options */
        $options = $product->options()
            ->with(['values' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductOption $option) => $option->values->isNotEmpty());

        if ($options->isEmpty()) {
            return [];
        }

        $combinations = [[]];

        foreach ($options as $option) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($option->values as $value) {
                    $next[] = [...$combination, $option->name => $value->value];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * The combinations that do not have a variant yet.
     *
     * @return list<array<string, string>>
     */
    public function missing(Product $product): array
    {
        $existing = $product->variants()->get(['id', 'option_values'])
            ->map(fn (ProductVariant $variant) => self::signature((array) $variant->option_values))
            ->all();

        return array_values(array_filter(
            $this->combinations($product),
            fn (array $combination) => ! in_array(self::signature($combination), $existing, true),
        ));
    }

    /**
     * Create the missing variants. Returns how many were made.
     *
     * @throws CatalogueException When there would be too many.
     */
    public function generate(Product $product): int
    {
        return DB::transaction(function () use ($product) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $combinations = $this->combinations($product);

            if (count($combinations) > self::LIMIT) {
                throw new CatalogueException(
                    'These options make '.count($combinations).' combinations, which is more than the '.self::LIMIT.' a product can have. Remove some values.',
                );
            }

            $missing = $this->missing($product);
            $hasDefault = $product->variants()->where('is_default', true)->exists();

            foreach ($missing as $combination) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'name' => implode(' / ', $combination),
                    'sku' => $this->sku($product, $combination),
                    'option_values' => $combination,
                    'is_default' => ! $hasDefault,
                    'is_active' => true,
                ]);

                $hasDefault = true;
            }

            return count($missing);
        });
    }

    /**
     * A stable identity for a combination, so two maps with the same pairs in a different
     * order are the same combination.
     *
     * @param  array<string, string>  $optionValues
     */
    public static function signature(array $optionValues): string
    {
        ksort($optionValues);

        return json_encode($optionValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A SKU such as HOODIE-BLACK-M that no other variant has.
     *
     * @param  array<string, string>  $combination
     */
    private function sku(Product $product, array $combination): string
    {
        $base = Str::upper(Str::limit(Str::slug(implode('-', [$product->slug, ...array_values($combination)])), 240, ''));
        $sku = $base;
        $suffix = 1;

        while (ProductVariant::query()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.(++$suffix);
        }

        return $sku;
    }
}
