<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForumCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'access_permission',
        'is_published',
        'position',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function boards(): HasMany
    {
        return $this->hasMany(ForumBoard::class)->orderBy('position');
    }
}
