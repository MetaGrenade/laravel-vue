<?php

namespace App\Models;

use App\Support\Commerce\PriceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Price extends Model
{
    use HasFactory;

    protected $fillable = [
        'priceable_type',
        'priceable_id',
        'currency',
        'amount',
        'compare_at_amount',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'compare_at_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Prices a shopper can be charged: active, and in the shop's currency. Checkout
     * ({@see PriceResolver}) and the storefront both use this, so what is
     * shown is what is charged.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeChargeable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('currency', strtoupper((string) config('commerce.currency', 'USD')));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }
}
