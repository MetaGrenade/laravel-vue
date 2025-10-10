<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'stripe_price_id',
        'interval',
        'price',
        'currency',
        'description',
        'features',
        'is_active',
    ];

    protected $casts = [
        'price' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $plan) {
            if (! $plan->slug) {
                $plan->slug = Str::slug($plan->name);
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }
}
