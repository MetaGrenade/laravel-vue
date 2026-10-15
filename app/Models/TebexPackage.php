<?php

namespace App\Models;

use App\Payments\Tebex\TebexCatalogue;
use App\Support\Commerce\Money;
use Database\Factories\TebexPackageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A package of a Tebex store, as the last synchronisation saw it. Written only by
 * {@see TebexCatalogue}: Tebex owns the catalogue and its prices.
 */
class TebexPackage extends Model
{
    /** @use HasFactory<TebexPackageFactory> */
    use HasFactory;

    public const SINGLE = 'single';

    public const SUBSCRIPTION = 'subscription';

    protected $fillable = [
        'tebex_id',
        'ident',
        'tebex_category_id',
        'name',
        'slug',
        'description',
        'image_url',
        'type',
        'base_price',
        'sales_tax',
        'total_price',
        'currency',
        'prorate_price',
        'discount',
        'disable_quantity',
        'disable_gifting',
        'expires_at',
        'sort_order',
        'user_limit',
        'media',
        'options',
        'variables',
        'removed_at',
    ];

    protected $casts = [
        'tebex_id' => 'integer',
        'tebex_category_id' => 'integer',
        'base_price' => 'decimal:2',
        'sales_tax' => 'decimal:2',
        'total_price' => 'decimal:2',
        'prorate_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'disable_quantity' => 'boolean',
        'disable_gifting' => 'boolean',
        'expires_at' => 'datetime',
        'sort_order' => 'integer',
        'user_limit' => 'integer',
        'media' => 'array',
        'options' => 'array',
        'variables' => 'array',
        'removed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<TebexCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TebexCategory::class, 'tebex_category_id');
    }

    /**
     * Packages Tebex still lists.
     *
     * @param  Builder<TebexPackage>  $query
     * @return Builder<TebexPackage>
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function isSubscription(): bool
    {
        return $this->type === self::SUBSCRIPTION;
    }

    /**
     * Whether the buyer has to choose something (a server, a rank) before it can be bought.
     */
    public function hasVariables(): bool
    {
        return is_array($this->variables) && $this->variables !== [];
    }

    /**
     * What the buyer pays before the tax Tebex adds for their country.
     */
    public function basePrice(): Money
    {
        return Money::parse($this->base_price, $this->currency);
    }
}
