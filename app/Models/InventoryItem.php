<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'quantity',
        'allow_backorder',
        'delivers_keys',
    ];

    protected $casts = [
        'allow_backorder' => 'boolean',
        'delivers_keys' => 'boolean',
    ];

    /**
     * The stock a purchase of this product (or variant) takes from: the variant's own, and failing
     * that the product's. The one rule for "which stock is this line", so what reserves the stock
     * and what delivers its licence keys can never disagree.
     */
    public static function resolveFor(?int $productId, ?int $variantId): ?self
    {
        if ($variantId !== null) {
            $own = static::query()->where('product_variant_id', $variantId)->first();

            if ($own !== null) {
                return $own;
            }
        }

        if ($productId === null) {
            return null;
        }

        return static::query()->where('product_id', $productId)->whereNull('product_variant_id')->first();
    }

    /**
     * The stock an order line takes from. Once the line has reserved some it is the row it reserved from,
     * whatever has been added since (a variant given a stock record of its own after the order was placed
     * must not move the line to it), so the unit that was held, the key that is handed over and the stock
     * a refund returns all come from the same place. A line that has reserved nothing yet is resolved the
     * way a new purchase is.
     */
    public static function forOrderItem(OrderItem $item): ?self
    {
        $reserved = InventoryMovement::query()
            ->where('order_item_id', $item->id)
            ->where('reason', InventoryMovement::RESERVATION)
            ->orderByDesc('id')
            ->value('inventory_item_id');

        if ($reserved !== null && ($row = static::query()->find($reserved)) !== null) {
            return $row;
        }

        return static::resolveFor($item->product_id, $item->product_variant_id);
    }

    /**
     * Stock that belongs to something a shopper can still buy: a product that is on sale and,
     * for a variant's stock, a variant that is on. What is archived or switched off keeps its
     * stock row for the history, but is not a shortage anyone needs to act on.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForSale(Builder $query): Builder
    {
        return $query
            ->whereHas('product', fn ($product) => $product->where('is_active', true))
            ->where(function ($inner) {
                $inner->whereNull('product_variant_id')
                    ->orWhereHas('variant', fn ($variant) => $variant->where('is_active', true));
            });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * The pool of licence keys this stock is sold from (only when `delivers_keys`).
     *
     * @return HasMany<LicenceKey, $this>
     */
    public function licenceKeys(): HasMany
    {
        return $this->hasMany(LicenceKey::class);
    }
}
