<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Price extends Model
{
    use HasFactory;

    protected $fillable = [
        'priceable_type',
        'priceable_id',
        'currency',
        'amount',
        'compare_at_amount',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'compare_at_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }
}
