<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'product_variant_id',
        'tebex_package_id',
        'quantity',
        'unit_price',
        'total',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
     * The Tebex package this line is, when it is one (it is then not a product).
     *
     * @return BelongsTo<TebexPackage, $this>
     */
    public function tebexPackage(): BelongsTo
    {
        return $this->belongsTo(TebexPackage::class);
    }

    public function isTebexPackage(): bool
    {
        return $this->tebex_package_id !== null;
    }
}
