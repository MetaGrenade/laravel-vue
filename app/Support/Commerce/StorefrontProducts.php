<?php

namespace App\Support\Commerce;

use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;

/**
 * A product as the shop window shows it on a card (the catalogue, a wishlist): only what a shopper should
 * see, which is prices that can be charged, variants that are on sale, a picture, and how much is left in
 * words rather than numbers. The product page uses the same variant and price shapes.
 */
class StorefrontProducts
{
    public function __construct(private readonly ProductAvailability $availability) {}

    /**
     * Products on sale, with everything a card needs loaded.
     *
     * @return Builder<Product>
     */
    public static function query(): Builder
    {
        return Product::query()
            ->with([
                'variants' => ProductAvailability::activeVariants(),
                'prices' => ProductAvailability::chargeablePrices(),
                'inventoryItems:id,product_id,product_variant_id,quantity,allow_backorder',
                'primaryImage',
                'categories:id,name,slug',
                'tags:id,name,slug',
                'brand:id,name,slug',
            ])
            ->withExists('variants as has_variants')
            ->where('is_active', true);
    }

    /**
     * @param  array{average: float, count: int}|null  $rating  What published reviews say, if any.
     * @return array<string, mixed>
     */
    public function card(Product $product, ?array $rating = null): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'is_active' => $product->is_active,
            // What checkout will accept, so the button is never on for something it would refuse.
            'can_buy' => $this->availability->canBuy($product),
            'sold_out' => $this->availability->soldOut($product),
            'image' => $product->primaryImage?->toStorefront($product->name),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => $this->variant($variant, $product))->values(),
            'prices' => $product->prices->map(fn (Price $price) => $this->price($price))->values(),
            'brand' => $product->brand,
            'categories' => $product->categories,
            'tags' => $product->tags,
            'rating' => $rating,
        ];
    }

    /**
     * @return array{id: int, name: string, sku: string|null, option_values: object, is_default: bool, stock: string, prices: list<array<string, mixed>>}
     */
    public function variant(ProductVariant $variant, Product $product): array
    {
        return [
            'id' => $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'option_values' => (object) ((array) $variant->option_values),
            'is_default' => $variant->is_default,
            'stock' => $this->availability->status($product, $variant),
            'prices' => $variant->prices->map(fn (Price $price) => $this->price($price))->values()->all(),
        ];
    }

    /**
     * @return array{id: int, currency: string, amount: string, compare_at_amount: string|null}
     */
    public function price(Price $price): array
    {
        return [
            'id' => $price->id,
            'currency' => $price->currency,
            'amount' => $price->amount,
            'compare_at_amount' => $price->compare_at_amount,
        ];
    }
}
