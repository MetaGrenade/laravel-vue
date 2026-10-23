<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * An API key (see docs/api-keys.md): a Sanctum token with the columns the site adds, the day it was revoked and its own rate limits.
 *
 * Sanctum itself turns a token away only once it has expired; a revoked one is turned away by the callback registered in
 * `AppServiceProvider`, which asks {@see self::isRevoked()}.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'hourly_quota' => 'integer',
        'daily_quota' => 'integer',
    ];

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether it can still be used: not revoked and not expired.
     */
    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Keys that can still be used.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('revoked_at'))
            ->where(fn (Builder $inner) => $inner
                ->whereNull($this->qualifyColumn('expires_at'))
                ->orWhere($this->qualifyColumn('expires_at'), '>', now()));
    }
}
