<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchQuery extends Model
{
    use HasFactory;

    protected $fillable = [
        'term',
        'result_count',
    ];

    protected $casts = [
        'result_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
