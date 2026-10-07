<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingWebhookCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'external_id',
        'stripe_id',
        'type',
        'payload',
        'signature_valid',
        'attempts',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
