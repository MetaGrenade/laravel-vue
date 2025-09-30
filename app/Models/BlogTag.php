<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BlogTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function blogs(): BelongsToMany
    {
        return $this->belongsToMany(Blog::class, 'blog_blog_tag')
            ->withTimestamps();
    }
}
