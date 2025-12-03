<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogCommentReaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'blog_comment_id',
        'user_id',
        'reaction',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(BlogComment::class, 'blog_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
