<?php

namespace App\Models;

use App\Support\Billing\Dunning;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email the customer was sent about a payment that failed (or came right). Written by {@see Dunning}, which claims one
 * before sending so that a webhook delivered twice, or replayed, tells the customer once.
 */
class BillingDunningNotice extends Model
{
    public const FAILED = 'failed';

    public const RECOVERED = 'recovered';

    public const CANCELLED = 'cancelled';

    public const TRIAL_ENDING = 'trial_ending';

    protected $fillable = [
        'user_id',
        'kind',
        'reference',
        'attempt',
    ];

    protected $casts = [
        'attempt' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
