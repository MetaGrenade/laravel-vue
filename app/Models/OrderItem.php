<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'tebex_package_id',
        'quantity',
        'unit_price',
        'subtotal',
        'tax_total',
        'discount_total',
        'requires_shipping',
        'delivers_keys',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'requires_shipping' => 'boolean',
        'delivers_keys' => 'boolean',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The Tebex package this line was bought as, when it is one (it is then not a product).
     *
     * @return BelongsTo<TebexPackage, $this>
     */
    public function tebexPackage(): BelongsTo
    {
        return $this->belongsTo(TebexPackage::class);
    }

    /**
     * The right to download this line's files, once it is paid for (only lines whose product has files).
     *
     * @return HasOne<DownloadGrant, $this>
     */
    public function downloadGrant(): HasOne
    {
        return $this->hasOne(DownloadGrant::class);
    }

    /**
     * The licence keys handed to this line, once it is paid for (only lines sold as licence keys).
     *
     * @return HasMany<LicenceKey, $this>
     */
    public function licenceKeys(): HasMany
    {
        return $this->hasMany(LicenceKey::class);
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
}
