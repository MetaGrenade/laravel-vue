<?php

namespace App\Models;

use App\Enums\EntitlementType;
use Database\Factories\EntitlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something an account can be given: a feature it may use, or a limit it may raise. Code asks for one by its `key`,
 * which is why the key (and the type, which decides what a grant's value means) cannot be changed once it exists.
 */
class Entitlement extends Model
{
    /** @use HasFactory<EntitlementFactory> */
    use HasFactory;

    /**
     * The shape of a key: lower case, starting with a letter, with dots, dashes or underscores between words
     * ("api.rate-limit", "team_seats").
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9]*([._-][a-z0-9]+)*$/';

    protected $fillable = [
        'key',
        'label',
        'description',
        'type',
    ];

    protected $casts = [
        'type' => EntitlementType::class,
    ];

    /**
     * The Tebex packages that give it, each with how much per unit bought (for a limit).
     *
     * @return HasMany<TebexPackageEntitlement, $this>
     */
    public function packageLinks(): HasMany
    {
        return $this->hasMany(TebexPackageEntitlement::class);
    }

    /**
     * The subscription plans that give it, each with how much (for a limit).
     *
     * @return HasMany<SubscriptionPlanEntitlement, $this>
     */
    public function planLinks(): HasMany
    {
        return $this->hasMany(SubscriptionPlanEntitlement::class);
    }

    /**
     * @return HasMany<UserEntitlement, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    public function isLimit(): bool
    {
        return $this->type === EntitlementType::Limit;
    }
}
