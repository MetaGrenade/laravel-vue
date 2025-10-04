<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogRevision extends Model
{
    use HasFactory;

    /**
     * Ensure revision timestamps retain microsecond precision.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

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
