<?php

namespace App\Models;

use App\Support\Commerce\Countries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'countries',
        'position',
        'is_active',
    ];

    protected $casts = [
        'countries' => 'array',
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<ShippingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class)->orderBy('position')->orderBy('id');
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
     * Whether this zone lists the country itself (not just "everywhere else").
     */
    public function listsCountry(string $country): bool
    {
        return in_array(strtoupper($country), array_map('strtoupper', $this->countries ?? []), true);
    }

    /**
     * Whether this zone is the "rest of world" catch-all.
     */
    public function isCatchAll(): bool
    {
        return in_array(Countries::ANY, $this->countries ?? [], true);
    }
}
