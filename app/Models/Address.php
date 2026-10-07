<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved address in a customer's address book.
 */
class Address extends Model
{
    use HasFactory;
    use HasOwner;

    protected $fillable = [
        'label',
        'name',
        'company',
        'line1',
        'line2',
        'city',
        'region',
        'postal_code',
        'country',
        'phone',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];
}
