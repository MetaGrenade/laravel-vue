<?php

namespace App\Models;

use App\Support\Commerce\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_zone_id',
        'name',
        'description',
        'amount',
        'min_subtotal',
        'max_subtotal',
        'position',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'max_subtotal' => 'decimal:2',
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether the rate is offered for a given subtotal of shippable items.
     */
    public function appliesTo(Money $subtotal): bool
    {
        $currency = $subtotal->currency;

        if ($this->min_subtotal !== null && $subtotal->minor < Money::parse($this->min_subtotal, $currency)->minor) {
            return false;
        }

        if ($this->max_subtotal !== null && $subtotal->minor > Money::parse($this->max_subtotal, $currency)->minor) {
            return false;
        }

        return true;
    }
}
