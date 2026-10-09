<?php

namespace App\Models;

use App\Enums\CouponType;
use App\Support\Commerce\Discounts\CouponCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
        'type',
        'value',
        'currency',
        'minimum_subtotal',
        'starts_at',
        'ends_at',
        'max_redemptions',
        'max_redemptions_per_customer',
        'is_active',
    ];

    protected $casts = [
        'type' => CouponType::class,
        'value' => 'decimal:4',
        'minimum_subtotal' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'max_redemptions' => 'integer',
        'max_redemptions_per_customer' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Codes are kept in one form so they can be found however they are typed.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $code) => CouponCode::normalise($code));
    }

    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', CouponCode::normalise($code))->first();
    }

    /**
     * Products the code is limited to. With no products and no categories it applies to everything.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_product');
    }

    /**
     * @return BelongsToMany<ProductCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'coupon_product_category');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Where the code stands for staff: `inactive` (switched off), `expired`, `scheduled` (not yet
     * started), `used_up` (every use taken) or `active`.
     *
     * @param  int  $uses  How many orders it is on, which the caller counts.
     */
    public function status(int $uses): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return 'expired';
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->max_redemptions !== null && $uses >= $this->max_redemptions) {
            return 'used_up';
        }

        return 'active';
    }

    /**
     * Whether the code is limited to some products. Needs `products` and `categories` loaded, or
     * asks the database.
     */
    public function isRestricted(): bool
    {
        if ($this->relationLoaded('products') && $this->relationLoaded('categories')) {
            return $this->products->isNotEmpty() || $this->categories->isNotEmpty();
        }

        return $this->products()->exists() || $this->categories()->exists();
    }
}
