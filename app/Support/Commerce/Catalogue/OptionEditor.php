<?php

namespace App\Support\Commerce\Catalogue;

use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Changes to a product's options and their values.
 *
 * A variant records the options it was made from by name ({"Size": "M"}), so renaming an
 * option or a value has to rewrite those records or the variants would stop matching it, and
 * removing one that a variant still uses is refused rather than leaving a variant that
 * describes something that no longer exists.
 */
class OptionEditor
{
    public function renameOption(ProductOption $option, string $name): ProductOption
    {
        return DB::transaction(function () use ($option, $name) {
            $old = $option->name;

            if ($old !== $name) {
                $this->rewriteVariants($option->product_id, function (array $map) use ($old, $name) {
                    if (! array_key_exists($old, $map)) {
                        return $map;
                    }

                    // Keep the position of the pair: rebuild the map rather than add and remove.
                    $renamed = [];

                    foreach ($map as $key => $value) {
                        $renamed[$key === $old ? $name : $key] = $value;
                    }

                    return $renamed;
                });
            }

            $option->forceFill(['name' => $name])->save();

            return $option;
        });
    }

    public function renameValue(ProductOptionValue $value, string $new): ProductOptionValue
    {
        return DB::transaction(function () use ($value, $new) {
            $option = $value->option()->firstOrFail();
            $old = $value->value;

            if ($old !== $new) {
                $this->rewriteVariants($option->product_id, function (array $map) use ($option, $old, $new) {
                    if (($map[$option->name] ?? null) === $old) {
                        $map[$option->name] = $new;
                    }

                    return $map;
                });
            }

            $value->forceFill(['value' => $new])->save();

            return $value;
        });
    }

    /**
     * @throws CatalogueException When a variant is made from the option.
     */
    public function deleteOption(ProductOption $option): void
    {
        $count = $this->variantsUsing($option->product_id, fn (array $map) => array_key_exists($option->name, $map));

        if ($count > 0) {
            throw new CatalogueException("{$count} ".($count === 1 ? 'variant is' : 'variants are')." made from {$option->display_name}. Delete or change them first.");
        }

        $option->delete();
    }

    /**
     * @throws CatalogueException When a variant is made from the value.
     */
    public function deleteValue(ProductOptionValue $value): void
    {
        $option = $value->option()->firstOrFail();

        $count = $this->variantsUsing($option->product_id, fn (array $map) => ($map[$option->name] ?? null) === $value->value);

        if ($count > 0) {
            throw new CatalogueException("{$count} ".($count === 1 ? 'variant is' : 'variants are')." made from {$option->display_name}: {$value->value}. Delete or change them first.");
        }

        $value->delete();
    }

    /**
     * @param  callable(array<string, string>): bool  $uses
     */
    private function variantsUsing(int $productId, callable $uses): int
    {
        return ProductVariant::query()
            ->where('product_id', $productId)
            ->get(['id', 'option_values'])
            ->filter(fn (ProductVariant $variant) => $uses((array) $variant->option_values))
            ->count();
    }

    /**
     * @param  callable(array<string, string>): array<string, string>  $rewrite
     */
    private function rewriteVariants(int $productId, callable $rewrite): void
    {
        ProductVariant::query()->where('product_id', $productId)->get()->each(function (ProductVariant $variant) use ($rewrite) {
            $before = (array) $variant->option_values;
            $after = $rewrite($before);

            if ($after !== $before) {
                $variant->forceFill(['option_values' => $after])->save();
            }
        });
    }
}
