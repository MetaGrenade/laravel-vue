<?php

namespace App\Support\Commerce;

use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;

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

    private function lowest(Product|ProductVariant $priceable): ?Price
    {
        return $priceable->prices()
            ->where('is_active', true)
            ->where('currency', $this->currency())
            ->orderBy('amount')
            ->first();
    }
}
