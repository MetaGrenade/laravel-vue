<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a subscription plan gives the account that holds it: an entitlement and, for a limit, how much. Set by staff.
 */
class SubscriptionPlanEntitlement extends Model
{
    protected $fillable = [
        'subscription_plan_id',
        'entitlement_id',
        'value',
    ];

    protected $casts = [
        'value' => 'integer',
    ];

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * @return BelongsTo<Entitlement, $this>
     */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }
}
