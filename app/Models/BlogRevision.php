<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'blog_id',
        'editor_id',
        'title',
        'excerpt',
        'body',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }
}
