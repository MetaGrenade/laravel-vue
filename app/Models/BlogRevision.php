<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class BlogRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'blog_id',
        'edited_by_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_image',
        'status',
        'published_at',
        'scheduled_for',
        'category_ids',
        'tag_ids',
        'metadata',
        'edited_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'edited_at' => 'datetime',
        'category_ids' => 'array',
        'tag_ids' => 'array',
        'metadata' => 'array',
    ];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id');
    }

    public static function recordSnapshot(Blog $blog, ?User $editor = null): self
    {
        $blog->loadMissing(['categories:id', 'tags:id']);

        $metadata = [
            'author_id' => $blog->user_id,
            'preview_token' => $blog->preview_token,
            'views' => $blog->views,
            'last_viewed_at' => optional($blog->last_viewed_at)?->toIso8601String(),
        ];

        $metadata = Arr::where($metadata, fn ($value) => $value !== null);

        $now = Carbon::now();

        $previous = static::query()
            ->where('blog_id', $blog->id)
            ->latest('created_at')
            ->first();

        if ($previous && $previous->created_at && $previous->created_at >= $now) {
            $now = $previous->created_at->copy()->addSecond();
        }

        return static::create([
            'blog_id' => $blog->id,
            'edited_by_id' => $editor?->getKey(),
            'title' => $blog->title,
            'slug' => $blog->slug,
            'excerpt' => $blog->excerpt,
            'body' => $blog->body,
            'cover_image' => $blog->cover_image,
            'status' => $blog->status,
            'published_at' => $blog->published_at,
            'scheduled_for' => $blog->scheduled_for,
            'category_ids' => $blog->categories->pluck('id')->values()->all(),
            'tag_ids' => $blog->tags->pluck('id')->values()->all(),
            'metadata' => $metadata === [] ? null : $metadata,
            'edited_at' => $blog->updated_at,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
