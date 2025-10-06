<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchQueryAggregate extends Model
{
    protected $fillable = [
        'term',
        'total_count',
        'total_results',
        'zero_result_count',
        'last_ran_at',
    ];

    protected $casts = [
        'total_count' => 'integer',
        'total_results' => 'integer',
        'zero_result_count' => 'integer',
        'last_ran_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
