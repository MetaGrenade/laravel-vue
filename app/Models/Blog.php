<?php

namespace App\Models;

use App\Casts\SanitizedHtml;
use App\Models\Concerns\HasSearchDocument;
use App\Support\Search\SearchText;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Blog extends Model
{
    use HasFactory;
    use HasSearchDocument;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'cover_image',
        'body',
        'user_id',
        'status',
        'comments_enabled',
        'published_at',
        'scheduled_for',
        'preview_token',
        'views',
        'last_viewed_at',
    ];

    protected $casts = [
        'body' => SanitizedHtml::class.':article',
        'published_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'last_viewed_at' => 'datetime',
        'comments_enabled' => 'bool',
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

    /**
     * The comments that are shown: not the ones a moderator has rejected or that are waiting to be approved.
     *
     * @return HasMany<BlogComment, $this>
     */
    public function approvedComments(): HasMany
    {
        return $this->comments()->where('status', BlogComment::STATUS_APPROVED);
    }

    public function views(): HasMany
    {
        return $this->hasMany(BlogView::class);
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

    public function searchableAs(): string
    {
        return $this->searchIndexAs('blogs');
    }

    /**
     * A published post, or a scheduled one whose time has arrived. The same
     * rule the database search uses.
     */
    public function shouldBeSearchable(): bool
    {
        if ($this->status === 'published') {
            return true;
        }

        $due = $this->scheduled_for;

        return $this->status === 'scheduled'
            && $due instanceof CarbonInterface
            && $due->lessThanOrEqualTo(now());
    }

    /**
     * @return array<string, string>
     */
    public function toSearchableArray(): array
    {
        return $this->searchDocument([
            'title' => (string) $this->title,
            'excerpt' => SearchText::plain($this->excerpt),
            'body' => SearchText::plain($this->body),
            'author' => $this->searchNickname($this->user_id),
        ]);
    }
}
