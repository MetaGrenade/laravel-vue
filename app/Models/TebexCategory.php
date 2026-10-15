<?php

namespace App\Models;

use App\Payments\Tebex\TebexCatalogue;
use Database\Factories\TebexCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category of a Tebex store, as the last synchronisation saw it. Written only by
 * {@see TebexCatalogue}: Tebex owns the catalogue.
 */
class TebexCategory extends Model
{
    /** @use HasFactory<TebexCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'tebex_id',
        'parent_tebex_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'display_type',
        'image_url',
        'is_dynamic',
        'is_tiered',
        'removed_at',
    ];

    protected $casts = [
        'tebex_id' => 'integer',
        'parent_tebex_id' => 'integer',
        'sort_order' => 'integer',
        'is_dynamic' => 'boolean',
        'is_tiered' => 'boolean',
        'removed_at' => 'datetime',
    ];

    /**
     * @return HasMany<TebexPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(TebexPackage::class);
    }

    /**
     * Categories Tebex still lists.
     *
     * @param  Builder<TebexCategory>  $query
     * @return Builder<TebexCategory>
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }
}
