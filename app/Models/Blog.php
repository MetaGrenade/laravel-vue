<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Blog extends Model
{
    use HasFactory;

    /**
     * Ensure date attributes maintain microsecond precision when stored.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'cover_image',
        'body',
        'user_id',
        'status',
        'published_at',
        'scheduled_for',
        'preview_token',
        'views',
        'last_viewed_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'last_viewed_at' => 'datetime',
    ];

    /**
     * Boot method to auto-generate the slug when creating a blog post.
     */
    protected static function booted()
    {
        static::creating(function ($blog) {
            if (empty($blog->slug)) {
                $blog->slug = Str::slug($blog->title);
            }
        });
    }

    /**
     * A blog post belongs to a user (author).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BlogComment::class);
    }

    public function commentSubscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blog_comment_subscriptions')
            ->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(BlogCategory::class, 'blog_blog_category')
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_blog_tag')
            ->withTimestamps();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BlogRevision::class);
    }
}
