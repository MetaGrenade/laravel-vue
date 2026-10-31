<?php

namespace App\Support\Commerce;

use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Decides what a product costs right now. Checkout always asks here instead
 * of trusting a price saved in the cart.
 */
class PriceResolver
{
    public function currency(): string
    {
        return strtoupper((string) config('commerce.currency', 'USD'));
    }

    /**
     * The active price in the store currency: the variant's own price when it
     * has one, otherwise the product's. Null means it cannot be bought.
     */
    public function resolve(Product $product, ?ProductVariant $variant = null): ?Price
    {
        if ($variant !== null) {
            $price = $this->lowest($variant);

            if ($price !== null) {
                return $price;
            }
        }

        return $this->lowest($product);
    }

    /**
     * What each line of a cart costs right now: {@see self::resolve()} for every line, in one query however many lines there are
     * (resolving them one at a time asks two per line, and a cart page or a checkout is priced on every request).
     *
     * @param  iterable<CartItem>  $items  With their product and variant loaded.
     * @return array<int, Price|null> By line id; null for a line that has nothing to charge for (or no product).
     */
    public function resolveLines(iterable $items): array
    {
        $items = collect($items);
        $productIds = $items->map(fn (CartItem $item) => $item->product?->id)->filter()->unique()->values()->all();
        $variantIds = $items->map(fn (CartItem $item) => $item->variant?->id)->filter()->unique()->values()->all();

        if ($productIds === [] && $variantIds === []) {
            return [];
        }

        $productType = (new Product)->getMorphClass();
        $variantType = (new ProductVariant)->getMorphClass();

        $lowest = Price::query()
            ->chargeable()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('priceable_type', $productType)->whereIn('priceable_id', $productIds))
                ->orWhere(fn ($query) => $query->where('priceable_type', $variantType)->whereIn('priceable_id', $variantIds)))
            ->orderBy('amount')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Price $price) => $price->priceable_type.':'.$price->priceable_id)
            ->map(fn (Collection $prices) => $prices->first());

        $resolved = [];

        foreach ($items as $item) {
            $resolved[$item->id] = ($item->variant !== null ? $lowest->get($variantType.':'.$item->variant->id) : null)
                ?? ($item->product !== null ? $lowest->get($productType.':'.$item->product->id) : null);
        }

        return $resolved;
    }

    private function lowest(Product|ProductVariant $priceable): ?Price
    {
        return $priceable->prices()
            ->chargeable()
            ->orderBy('amount')
            ->orderBy('id')
            ->first();
    }
}
