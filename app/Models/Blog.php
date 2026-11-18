<?php

namespace App\Models;

use App\Casts\SanitizedHtml;
use App\Models\Concerns\HasSearchDocument;
use App\Support\Blog\BlogCovers;
use App\Support\Search\SearchText;
use App\Support\Text\PlainText;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /**
     * `body_text` is for the search to read; no page or API response needs it, and it is as long as the body.
     *
     * @var list<string>
     */
    protected $hidden = ['body_text'];

    protected $casts = [
        'body' => SanitizedHtml::class.':article',
        'published_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'last_viewed_at' => 'datetime',
        'comments_enabled' => 'bool',
    ];

    /**
     * Boot method to auto-generate the slug when creating a blog post, and to keep `body_text` the words of the body.
     */
    protected static function booted()
    {
        static::creating(function ($blog) {
            if (empty($blog->slug)) {
                $blog->slug = Str::slug($blog->title);
            }
        });

        // The body is HTML as the sanitiser stores it (`Don&#039;t`, `</p><p>`); the database search reads the words of it, which are made
        // here, when the body is written, and not by every search for every post. Saving something else (a view counted, a status
        // changed) leaves it alone.
        static::saving(function (Blog $blog) {
            if ($blog->isDirty('body')) {
                $blog->setAttribute('body_text', PlainText::of($blog->body));
            }
        });

        // The cover's files go with the post. Its revisions are deleted with it by the database, which runs no hook, and the rows are
        // the only thing that says which files were theirs, so the files are found before the post goes and removed after.
        static::deleting(function (Blog $blog) {
            $blog->coverPathsToForget = app(BlogCovers::class)->pathsOfPosts(Blog::query()->whereKey($blog->getKey())->select('id'));
        });

        static::deleted(function (Blog $blog) {
            app(BlogCovers::class)->forget($blog->coverPathsToForget);
            $blog->coverPathsToForget = [];
        });
    }

    /**
     * The covers this post is being deleted with, found by `deleting` and removed by `deleted`.
     *
     * @var list<string>
     */
    private array $coverPathsToForget = [];

    /**
     * A blog post belongs to a user (author).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
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

    /**
     * Who is told about a new comment by this member: whoever follows the comments, and the post's author, but not the one commenting.
     *
     * @return list<int>
     */
    public function commentRecipientIds(User $commenter): array
    {
        $ids = [];

        foreach ($this->commentSubscribers()->where('users.id', '!=', $commenter->id)->pluck('users.id') as $id) {
            $ids[(int) $id] = (int) $id;
        }

        if ($this->user_id !== $commenter->id) {
            $ids[$this->user_id] = $this->user_id;
        }

        return array_values($ids);
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

    /**
     * Makes a scheduled post whose time has come a published one, dated the moment it was scheduled for. Saving it is what sends it to
     * the search index too: while its time was in the future it was not there, and nothing else says that the time has come.
     *
     * @return bool whether it was published now (false for any post that is not scheduled, or not yet due)
     */
    public function publishIfDue(): bool
    {
        $due = $this->scheduled_for;

        if ($this->status !== 'scheduled' || ! $due instanceof CarbonInterface || $due->greaterThan(now())) {
            return false;
        }

        $this->forceFill([
            'status' => 'published',
            'published_at' => $due,
            'scheduled_for' => null,
        ])->save();

        return true;
    }

    /**
     * Publishes every scheduled post whose time has come. The scheduler does it every minute (`PublishDueBlogs`), and a visit to the blog
     * or to the posts in the admin area does it again, so a site with no scheduler or no worker still publishes on time when it is looked at.
     *
     * @return int how many posts were published
     */
    public static function publishDue(): int
    {
        $published = 0;

        // By id: each post saved leaves the rows this selects, and a page that began at an offset would skip the ones behind it.
        static::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->eachById(function (Blog $blog) use (&$published): void {
                if ($blog->publishIfDue()) {
                    $published++;
                }
            }, 100);

        return $published;
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->where('status', 'published')
                ->orWhere(function (Builder $scheduled): void {
                    $scheduled->where('status', 'scheduled')
                        ->where('scheduled_for', '<=', now());
                });
        });
    }

    /**
     * @return array<string, string>
     */
    public function toSearchableArray(): array
    {
        return $this->searchDocument([
            'title' => (string) $this->title,
            'excerpt' => SearchText::plain($this->excerpt),
            'body' => SearchText::html($this->body),
            'author' => $this->searchNickname($this->user_id),
        ]);
    }
}
