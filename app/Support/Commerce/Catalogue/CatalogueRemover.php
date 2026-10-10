<?php

namespace App\Support\Commerce\Catalogue;

use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\Digital\ProductFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deleting products and variants without breaking what refers to them.
 *
 * Anything that has been ordered is kept for the order history and the stock ledger, so it
 * cannot be deleted, only switched off. Anything else can go, and everything that points at it
 * is cleaned up with it: carts lose the line (a cart line whose variant disappeared would
 * silently turn into a line for the base product at a different price) and its prices, which
 * are polymorphic and so not removed by a foreign key.
 */
class CatalogueRemover
{
    public function __construct(
        private readonly ProductImages $images,
        private readonly ProductFiles $downloads,
    ) {}

    public function productBlock(Product $product): ?string
    {
        return OrderItem::query()->where('product_id', $product->id)->exists()
            ? 'This product has been ordered, so it is kept for the order history. Archive it instead to take it out of the shop.'
            : null;
    }

    public function variantBlock(ProductVariant $variant): ?string
    {
        return OrderItem::query()->where('product_variant_id', $variant->id)->exists()
            ? 'This variant has been ordered, so it is kept for the order history. Switch it off instead to stop selling it.'
            : null;
    }

    /**
     * @throws CatalogueException When it has been ordered.
     */
    public function deleteProduct(Product $product): void
    {
        $files = [];
        $downloads = [];

        DB::transaction(function () use ($product, &$files, &$downloads) {
            // Held while the check and the delete happen, so two deletions cannot interleave.
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (($reason = $this->productBlock($locked)) !== null) {
                throw new CatalogueException($reason);
            }

            $variantIds = $locked->variants()->pluck('id')->all();
            $files = $this->images->filesOf($locked);
            $downloads = $this->downloads->filesOf($locked);

            $this->removeFromCarts(CartItem::query()->where('product_id', $locked->id));
            $this->removePrices($locked, $variantIds);

            // Options, values, variants, stock, picture rows and the category and tag links go with it.
            $locked->delete();
        });

        // The picture files are not covered by the foreign key. Removed after the commit, so a
        // failed deletion never leaves a product whose pictures have vanished.
        $this->images->deleteFiles($files);
        $this->downloads->deleteFiles($downloads);
    }

    /**
     * @throws CatalogueException When it has been ordered.
     */
    public function deleteVariant(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant) {
            Product::query()->whereKey($variant->product_id)->lockForUpdate()->first();

            if (($reason = $this->variantBlock($variant)) !== null) {
                throw new CatalogueException($reason);
            }

            $this->removeFromCarts(CartItem::query()->where('product_variant_id', $variant->id));
            $this->removePrices(null, [$variant->id]);

            $wasDefault = $variant->is_default;
            $productId = $variant->product_id;

            $variant->delete();

            // The product keeps a default as long as it has any variant.
            if ($wasDefault) {
                ProductVariant::query()->where('product_id', $productId)->orderBy('id')->limit(1)->update(['is_default' => true]);
            }
        });
    }

    /**
     * @param  list<int>  $variantIds
     */
    private function removePrices(?Product $product, array $variantIds): void
    {
        if ($product !== null) {
            Price::query()->where('priceable_type', $product->getMorphClass())->where('priceable_id', $product->id)->delete();
        }

        if ($variantIds !== []) {
            Price::query()->where('priceable_type', (new ProductVariant)->getMorphClass())->whereIn('priceable_id', $variantIds)->delete();
        }
    }

    /**
     * @param  Builder<CartItem>  $items
     */
    private function removeFromCarts(Builder $items): void
    {
        $lines = $items->with('cart')->get();

        foreach ($lines as $line) {
            $line->delete();
        }

        foreach ($lines->pluck('cart')->filter()->unique('id') as $cart) {
            CartManager::recalculate($cart);
        }
    }
}
