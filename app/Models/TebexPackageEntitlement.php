<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a Tebex package gives the account of whoever buys it: an entitlement and, for a limit, how much per unit
 * bought. Set by staff; Tebex knows nothing of it.
 */
class TebexPackageEntitlement extends Model
{
    protected $fillable = [
        'tebex_package_id',
        'entitlement_id',
        'value',
    ];

    protected $casts = [
        'value' => 'integer',
    ];

    /**
     * @return BelongsTo<TebexPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TebexPackage::class, 'tebex_package_id');
    }

    /**
     * @return BelongsTo<Entitlement, $this>
     */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }
}
