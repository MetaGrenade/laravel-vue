<?php

namespace App\Support\Commerce;

use App\Models\InventoryItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

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
     * The price an order for this would be charged: the variant's own, otherwise the product's (the
     * rule {@see PriceResolver} follows). Null means it cannot be bought. Needs `prices` loaded with
     * {@see self::chargeablePrices()}, which puts the cheapest first.
     */
    public function priceFor(Product $product, ?ProductVariant $variant = null): ?Price
    {
        return $variant?->prices->first() ?? $product->prices->first();
    }

    /**
     * The variants a shopper could actually buy: on sale and with a price to charge. A variant with
     * none of its own falls back to the product's price, and with neither it is not for sale, however
     * much of it there is. Needs `variants` loaded with {@see self::activeVariants()}.
     *
     * @return Collection<int, ProductVariant>
     */
    public function purchasableVariants(Product $product): Collection
    {
        return $product->variants
            ->filter(fn (ProductVariant $variant) => $this->priceFor($product, $variant) !== null)
            ->values();
    }

    /**
     * Whether everything that could be bought is out of stock: every purchasable variant, or the
     * product itself. Something that cannot be bought at all is not "sold out", it is unavailable.
     */
    public function soldOut(Product $product): bool
    {
        if ($this->hasVariants($product)) {
            $purchasable = $this->purchasableVariants($product);

            return $purchasable->isNotEmpty()
                && $purchasable->every(fn (ProductVariant $variant) => $this->status($product, $variant) === 'out');
        }

        return $this->priceFor($product) !== null && $this->status($product) === 'out';
    }

    /**
     * The cheapest price a shopper could pay, for structured data and "from" prices: each purchasable
     * variant at the price it would be charged (its own, or the product's when it has none), or the
     * product's own price when it has no variants. A product price that every variant overrides is
     * never charged, so it is never offered. Needs the same loaded relations as {@see self::canBuy()}.
     */
    public function lowestPrice(Product $product): ?Price
    {
        if (! $this->hasVariants($product)) {
            return $this->priceFor($product);
        }

        return $this->purchasableVariants($product)
            ->map(fn (ProductVariant $variant) => $this->priceFor($product, $variant))
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

        if (! $this->hasVariants($product)) {
            return $this->priceFor($product) !== null;
        }

        // A product that has variants is only ever sold as one of them: with every variant off
        // there is nothing to sell, however the product itself is priced.
        return $this->purchasableVariants($product)->isNotEmpty();
    }

    /**
     * Whether the product has variants at all, switched off or not: a product whose variants are
     * all off is not the same as one that never had any.
     */
    private function hasVariants(Product $product): bool
    {
        return (bool) ($product->getAttribute('has_variants') ?? $product->variants()->exists());
    }
}
