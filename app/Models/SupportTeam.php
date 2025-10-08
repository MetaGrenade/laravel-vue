<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function templates(): HasMany
    {
        return $this->hasMany(SupportResponseTemplate::class);
    }
}
