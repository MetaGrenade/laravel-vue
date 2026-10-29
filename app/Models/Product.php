<?php

namespace App\Models;

use App\Models\Concerns\HasSearchDocument;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Product extends Model
{
    use HasFactory;
    use HasSearchDocument;

    protected $fillable = [
        'brand_id',
        'name',
        'slug',
        'description',
        'metadata',
        'is_active',
        'requires_shipping',
        'is_taxable',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'requires_shipping' => 'boolean',
        'is_taxable' => 'boolean',
    ];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsToMany<ProductCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class);
    }

    /**
     * @return BelongsToMany<ProductTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ProductTag::class);
    }

    /**
     * @return HasMany<ProductOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }

    public function optionValues(): HasMany
    {
        return $this->hasManyThrough(ProductOptionValue::class, ProductOption::class);
    }

    /**
     * The files this product delivers once it is paid for, in the order staff arranged them.
     *
     * @return HasMany<ProductFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ProductFile::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * @return MorphMany<Price, $this>
     */
    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }

    /**
     * Pictures in the order they are shown; the first is the main one.
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The main picture.
     *
     * @return HasOne<ProductImage, $this>
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(['position' => 'min', 'id' => 'min']);
    }

    /**
     * @return HasMany<InventoryItem, $this>
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * Every review of the product, whatever its status. The public sees only the published ones.
     *
     * @return HasMany<ProductReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function searchableAs(): string
    {
        return $this->searchIndexAs('products');
    }

    /**
     * The shop shows a product that is for sale. An archived one is not.
     */
    public function shouldBeSearchable(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    public function toSearchableArray(): array
    {
        return $this->searchDocument([
            'name' => (string) $this->name,
            'description' => SearchText::plain($this->description),
            'brand' => $this->brandName(),
        ]);
    }

    private function brandName(): string
    {
        if ($this->brand_id === null) {
            return '';
        }

        $name = Brand::query()->whereKey($this->brand_id)->value('name');

        return is_string($name) ? $name : '';
    }
}
