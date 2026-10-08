<?php

namespace App\Support\Commerce;

use App\Models\Price;
use App\Models\Product;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What the shop may offer for sale, decided in one place so that what a shopper is shown and
 * what checkout will accept cannot drift apart.
 *
 * A price can be charged when it is active and in the shop's currency ({@see Price::scopeChargeable()}),
 * and a product can be bought when it is switched on and has something to charge: if it has
 * variants, a variant that is on (its own price, or the product's), otherwise its own price.
 */
class ProductAvailability
{
    /**
     * Eager-load constraint for a product's or variant's prices: only those that can be charged,
     * cheapest first, which is the order {@see PriceResolver} picks from.
     *
     * @return Closure(Relation<*, *, *>): mixed
     */
    public static function chargeablePrices(): Closure
    {
        return fn ($query) => $query->chargeable()->orderBy('amount');
    }

    /**
     * Eager-load constraint for a product's variants: only those that are on sale, with only
     * their chargeable prices.
     *
     * @return Closure(Relation<*, *, *>): mixed
     */
    public static function activeVariants(): Closure
    {
        return fn ($query) => $query->where('is_active', true)->with(['prices' => self::chargeablePrices()]);
    }

    /**
     * Whether a shopper can buy the product. Needs its `prices` and `variants` loaded with the
     * constraints above, and ideally `withExists('variants as has_variants')` so it can tell a
     * product with no variants from one whose variants are all switched off.
     */
    public function canBuy(Product $product): bool
    {
        if (! $product->is_active) {
            return false;
        }

        $productPriced = $product->prices->isNotEmpty();
        $hasVariants = $product->getAttribute('has_variants') ?? $product->variants()->exists();

        if (! $hasVariants) {
            return $productPriced;
        }

        // A product that has variants is only ever sold as one of them: with every variant off
        // there is nothing to sell, however the product itself is priced.
        return $product->variants->contains(fn ($variant) => $productPriced || $variant->prices->isNotEmpty());
    }
}
