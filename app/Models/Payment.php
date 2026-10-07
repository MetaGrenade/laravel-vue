<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay for an order through a provider.
 */
class Payment extends Model
{
    use HasFactory;
    use HasOwner;

    protected $fillable = [
        'order_id',
        'provider',
        'provider_reference',
        'provider_payment_id',
        'status',
        'amount',
        'currency',
        'fee',
        'checkout_url',
        'expires_at',
        'paid_at',
        'raw',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'raw' => 'array',
    ];

    /**
     * Provider payloads can hold customer details; keep them out of
     * serialised output (Inertia props, JSON responses) by default.
     *
     * @var list<string>
     */
    protected $hidden = ['raw', 'checkout_url'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
