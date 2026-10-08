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
    ];

    protected $casts = [
        'allow_backorder' => 'boolean',
    ];

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
}
