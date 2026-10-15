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

    /**
     * Packages the shop can offer: Tebex still lists them and they have not run out.
     *
     * @param  Builder<TebexPackage>  $query
     * @return Builder<TebexPackage>
     */
    public function scopeOnSale(Builder $query): Builder
    {
        return $query->whereNull('removed_at')
            ->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Why the shop cannot sell it, as the end of a sentence about the package ("{name} ..."), or null when it can.
     */
    public function whyNotForSale(): ?string
    {
        if ($this->removed_at !== null || ($this->expires_at !== null && $this->expires_at->isPast())) {
            return 'is no longer available';
        }

        if (blank($this->ident)) {
            return "can't be purchased right now";
        }

        // The buyer has to choose something (a server, a rank) before Tebex will take it, and the shop does not ask yet.
        if ($this->hasVariables()) {
            return 'needs choices the shop cannot collect yet, so it cannot be bought here';
        }

        // Tebex takes no payment for it, so nothing would tell the shop that it was bought: it is not sold from here.
        if ($this->basePrice()->isZero()) {
            return 'is free, which the shop cannot give out through Tebex yet';
        }

        return null;
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
