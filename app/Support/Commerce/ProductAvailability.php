<?php

namespace App\Support\Commerce;

use App\Models\InventoryItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
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
     * How much is left, in words: the shop tells customers whether they can get it, not how many
     * there are. `in_stock`, `low` (a few left), `out`, or `backorder` (none now, but orders are
     * taken and filled when it arrives). Stock that is not tracked is always in stock.
     */
    public function stockStatus(?InventoryItem $item): string
    {
        if ($item === null) {
            return 'in_stock';
        }

        if ($item->quantity <= 0) {
            return $item->allow_backorder ? 'backorder' : 'out';
        }

        if ($item->allow_backorder) {
            return 'in_stock';
        }

        return $item->quantity <= max(0, (int) config('commerce.low_stock_threshold', 5)) ? 'low' : 'in_stock';
    }

    /**
     * The stock an order for this would take from: the variant's own, otherwise the product's
     * (the same rule {@see InventoryReserver} follows). Needs `inventoryItems` loaded.
     */
    public function itemFor(Product $product, ?ProductVariant $variant = null): ?InventoryItem
    {
        $items = $product->inventoryItems;

        if ($variant !== null) {
            $own = $items->first(fn (InventoryItem $item) => $item->product_variant_id === $variant->id);

            if ($own !== null) {
                return $own;
            }
        }

        return $items->first(fn (InventoryItem $item) => $item->product_variant_id === null);
    }

    public function status(Product $product, ?ProductVariant $variant = null): string
    {
        return $this->stockStatus($this->itemFor($product, $variant));
    }

    /**
     * Whether everything on offer is out of stock: every variant that is on, or the product itself.
     */
    public function soldOut(Product $product): bool
    {
        $hasVariants = $product->getAttribute('has_variants') ?? $product->variants()->exists();

        if ($hasVariants) {
            return $product->variants->isNotEmpty()
                && $product->variants->every(fn (ProductVariant $variant) => $this->status($product, $variant) === 'out');
        }

        return $this->status($product) === 'out';
    }

    /**
     * The cheapest price a shopper could pay, for structured data and "from" prices. Needs the same
     * loaded relations as {@see self::canBuy()}.
     */
    public function lowestPrice(Product $product): ?Price
    {
        return $product->prices
            ->concat($product->variants->flatMap(fn (ProductVariant $variant) => $variant->prices))
            ->sortBy(fn (Price $price) => (float) $price->amount)
            ->first();
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
