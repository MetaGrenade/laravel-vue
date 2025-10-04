<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    use InteractsWithInertiaPagination;

    /**
     * Display a listing of published blog posts.
     */
    public function index(Request $request): Response
    {
        $categoryFilter = $request->string('category')->trim();
        $tagFilter = $request->string('tag')->trim();
        $searchFilter = $request->string('search')->trim();
        $sortFilter = $request->string('sort')->lower();

        $sortMode = in_array($sortFilter->toString(), ['latest', 'oldest', 'popular'], true)
            ? $sortFilter->toString()
            : 'latest';

        $this->publishDueScheduledBlogs();

        $blogsQuery = Blog::query()
            ->where(function ($query) {
                $query->where('status', 'published')
                    ->orWhere(function ($query) {
                        $query->where('status', 'scheduled')
                            ->whereNotNull('scheduled_for')
                            ->where('scheduled_for', '<=', now());
                    });
            })
            ->with([
                'user:id,nickname,avatar_url,profile_bio,social_links',
                'categories:id,name,slug',
                'tags:id,name,slug',
            ]);

        if ($categoryFilter->isNotEmpty()) {
            $blogsQuery->whereHas('categories', function ($query) use ($categoryFilter) {
                $query->where('slug', $categoryFilter->toString());
            });
        }

        if ($tagFilter->isNotEmpty()) {
            $blogsQuery->whereHas('tags', function ($query) use ($tagFilter) {
                $query->where('slug', $tagFilter->toString());
            });
        }

        if ($searchFilter->isNotEmpty()) {
            $blogsQuery->where(function ($query) use ($searchFilter) {
                $query->where('title', 'like', '%' . $searchFilter->toString() . '%')
                    ->orWhere('excerpt', 'like', '%' . $searchFilter->toString() . '%');
            });
        }

        if ($sortMode === 'popular') {
            $blogsQuery->withCount('comments')
                ->orderByDesc('comments_count')
                ->orderByDesc('published_at')
                ->orderByDesc('created_at');
        } elseif ($sortMode === 'oldest') {
            $blogsQuery->orderBy('published_at')
                ->orderBy('created_at');
        } else {
            $blogsQuery->orderByDesc('published_at')
                ->orderByDesc('created_at');
        }

        $blogs = $blogsQuery
            ->paginate(9)
            ->withQueryString();

        $blogItems = $blogs->getCollection()->map(function (Blog $blog) {
            return [
                'id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'cover_image' => $blog->cover_image
                    ? Storage::disk('public')->url($blog->cover_image)
                    : null,
                'views' => $blog->views,
                'last_viewed_at' => optional($blog->last_viewed_at)->toIso8601String(),
                'published_at' => optional($blog->published_at)->toIso8601String(),
                'author' => $this->transformAuthor($blog->user),
                'categories' => $blog->categories->map(function (BlogCategory $category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ];
                })->values()->all(),
                'tags' => $blog->tags->map(function (BlogTag $tag) {
                    return [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $categories = BlogCategory::query()
            ->whereHas('blogs', fn ($query) => $query->where('status', 'published'))
            ->withCount(['blogs as published_blogs_count' => fn ($query) => $query->where('status', 'published')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (BlogCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'count' => $category->published_blogs_count,
            ])
            ->values()
            ->all();

        $tags = BlogTag::query()
            ->whereHas('blogs', fn ($query) => $query->where('status', 'published'))
            ->withCount(['blogs as published_blogs_count' => fn ($query) => $query->where('status', 'published')])
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (BlogTag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'count' => $tag->published_blogs_count,
            ])
            ->values()
            ->all();

        return Inertia::render('Blog', [
            'blogs' => array_merge([
                'data' => $blogItems,
            ], $this->inertiaPagination($blogs)),
            'filters' => [
                'category' => $categoryFilter->isNotEmpty() ? $categoryFilter->toString() : null,
                'tag' => $tagFilter->isNotEmpty() ? $tagFilter->toString() : null,
                'search' => $searchFilter->isNotEmpty() ? $searchFilter->toString() : null,
                'sort' => $sortMode,
            ],
            'categories' => $categories,
            'tags' => $tags,
        ]);
    }

    /**
     * Provide an Atom feed containing the latest published blog posts.
     */
    public function feed(): HttpResponse
    {
        $this->publishDueScheduledBlogs();

        $posts = Blog::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get([
                'title',
                'slug',
                'excerpt',
                'published_at',
                'created_at',
                'updated_at',
            ]);

        $latestPost = $posts->first();
        $feedUpdatedAt = $latestPost
            ? ($latestPost->updated_at ?? $latestPost->published_at ?? $latestPost->created_at ?? now())
            : now();

        $entries = $posts->map(function (Blog $post) {
            $publishedAt = $post->published_at ?? $post->created_at ?? now();

            return [
                'title' => $post->title,
                'link' => route('blogs.view', ['slug' => $post->slug]),
                'excerpt' => $post->excerpt,
                'published_at' => $publishedAt->toAtomString(),
                'updated_at' => ($post->updated_at ?? $publishedAt)->toAtomString(),
            ];
        })->all();

        $xml = view('feeds.blog', [
            'title' => config('app.name') . ' Blog',
            'homeUrl' => route('blogs.index'),
            'selfUrl' => route('blogs.feed'),
            'updatedAt' => $feedUpdatedAt->toAtomString(),
            'entries' => $entries,
        ])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/atom+xml; charset=UTF-8',
        ]);
    }

    protected function publishDueScheduledBlogs(): void
    {
        Blog::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->each(function (Blog $dueBlog) {
                $dueBlog->forceFill([
                    'status' => 'published',
                    'published_at' => $dueBlog->scheduled_for,
                    'scheduled_for' => null,
                ])->save();
            });
    }

    /**
     * Show the detailed view for a single blog post.
     */
    public function show(Request $request, $slug): Response
    {
        $blog = Blog::with([
            'user:id,nickname,avatar_url,profile_bio,social_links',
            'categories:id,name,slug',
            'tags:id,name,slug',
        ])
            ->where('slug', $slug)
            ->where(function ($query) {
                $query->where('status', 'published')
                    ->orWhere(function ($query) {
                        $query->where('status', 'scheduled')
                            ->whereNotNull('scheduled_for')
                            ->where('scheduled_for', '<=', now());
                    });
            })
            ->firstOrFail();

        $blog->loadCount('commentSubscribers');

        if ($blog->status === 'scheduled' && $blog->scheduled_for && $blog->scheduled_for->lessThanOrEqualTo(now())) {
            $blog->forceFill([
                'status' => 'published',
                'published_at' => $blog->scheduled_for,
                'scheduled_for' => null,
            ])->save();
        }

        $this->registerBlogView($request, $blog);

        $comments = $blog->comments()
            ->with(['user:id,nickname,avatar_url,profile_bio'])
            ->orderBy('created_at')
            ->paginate(10, ['*'], 'page', 1);

        $commentItems = $comments->getCollection()
            ->map(function (BlogComment $comment) {
                $comment->loadMissing(['user:id,nickname,avatar_url,profile_bio']);

                $user = $comment->user;
                $avatarUrl = null;
                $profileBio = null;

                if ($user) {
                    $avatarCandidate = is_string($user->avatar_url) ? trim($user->avatar_url) : '';
                    $avatarUrl = $avatarCandidate !== '' ? $avatarCandidate : null;

                    $bioCandidate = is_string($user->profile_bio) ? trim($user->profile_bio) : '';
                    $profileBio = $bioCandidate !== '' ? $bioCandidate : null;
                }

                return [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'created_at' => optional($comment->created_at)->toIso8601String(),
                    'updated_at' => optional($comment->updated_at)->toIso8601String(),
                    'user' => $user ? [
                        'id' => $user->id,
                        'nickname' => $user->nickname,
                        'avatar_url' => $avatarUrl,
                        'profile_bio' => $profileBio,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $paginatedComments = array_merge([
            'data' => $commentItems,
        ], $this->inertiaPagination($comments));

        $coverImageUrl = $blog->cover_image
            ? Storage::disk('public')->url($blog->cover_image)
            : null;

        $canonicalUrl = route('blogs.view', ['slug' => $blog->slug]);
        $metaDescription = $blog->excerpt ? trim($blog->excerpt) : null;
        $metaImage = $coverImageUrl;
        $authorName = $blog->user && $blog->user->nickname
            ? trim($blog->user->nickname)
            : null;

        $categoryIds = $blog->categories->pluck('id');
        $tagIds = $blog->tags->pluck('id');

        $recommendationColumns = [
            'id',
            'title',
            'slug',
            'excerpt',
            'cover_image',
            'published_at',
        ];

        $relatedPosts = collect();

        if ($categoryIds->isNotEmpty() || $tagIds->isNotEmpty()) {
            $relatedPosts = Blog::query()
                ->where('id', '!=', $blog->id)
                ->where('status', 'published')
                ->where(function ($query) use ($categoryIds, $tagIds) {
                    if ($categoryIds->isNotEmpty() && $tagIds->isNotEmpty()) {
                        $query->whereHas('categories', function ($categoryQuery) use ($categoryIds) {
                            $categoryQuery->whereIn('blog_categories.id', $categoryIds);
                        })->orWhereHas('tags', function ($tagQuery) use ($tagIds) {
                            $tagQuery->whereIn('blog_tags.id', $tagIds);
                        });
                    } elseif ($categoryIds->isNotEmpty()) {
                        $query->whereHas('categories', function ($categoryQuery) use ($categoryIds) {
                            $categoryQuery->whereIn('blog_categories.id', $categoryIds);
                        });
                    } elseif ($tagIds->isNotEmpty()) {
                        $query->whereHas('tags', function ($tagQuery) use ($tagIds) {
                            $tagQuery->whereIn('blog_tags.id', $tagIds);
                        });
                    }
                })
                ->orderByDesc('published_at')
                ->orderByDesc('created_at')
                ->limit(3)
                ->get($recommendationColumns);
        }

        if ($relatedPosts->isEmpty()) {
            $relatedPosts = Blog::query()
                ->where('id', '!=', $blog->id)
                ->where('status', 'published')
                ->withCount('comments')
                ->orderByDesc('comments_count')
                ->orderByDesc('published_at')
                ->orderByDesc('created_at')
                ->limit(3)
                ->get($recommendationColumns);

            if ($relatedPosts->count() < 3) {
                $latestFallback = Blog::query()
                    ->where('id', '!=', $blog->id)
                    ->where('status', 'published')
                    ->whereNotIn('id', $relatedPosts->pluck('id'))
                    ->orderByDesc('published_at')
                    ->orderByDesc('created_at')
                    ->limit(3 - $relatedPosts->count())
                    ->get($recommendationColumns);

                $relatedPosts = $relatedPosts->concat($latestFallback);
            }
        }

        $recommendations = $relatedPosts
            ->map(function (Blog $relatedBlog) {
                return [
                    'id' => $relatedBlog->id,
                    'title' => $relatedBlog->title,
                    'slug' => $relatedBlog->slug,
                    'excerpt' => $relatedBlog->excerpt,
                    'cover_image' => $relatedBlog->cover_image
                        ? Storage::disk('public')->url($relatedBlog->cover_image)
                        : null,
                    'published_at' => optional($relatedBlog->published_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $renderTag = static function (string $tag, array $attributes): HtmlString {
            $attributeString = collect($attributes)
                ->map(fn ($value, $key) => sprintf('%s="%s"', $key, e($value)))
                ->implode(' ');

            $closing = $tag === 'meta' || $tag === 'link' ? ' />' : '>';

            return new HtmlString(sprintf('<%s %s%s', $tag, $attributeString, $closing));
        };

        $metaTags = collect([
            $metaDescription ? ['name' => 'description', 'content' => $metaDescription] : null,
            ['property' => 'og:type', 'content' => 'article'],
            ['property' => 'og:title', 'content' => $blog->title],
            $metaDescription ? ['property' => 'og:description', 'content' => $metaDescription] : null,
            ['property' => 'og:url', 'content' => $canonicalUrl],
            $metaImage ? ['property' => 'og:image', 'content' => $metaImage] : null,
            $authorName ? ['property' => 'article:author', 'content' => $authorName] : null,
            ['name' => 'twitter:card', 'content' => $metaImage ? 'summary_large_image' : 'summary'],
            ['name' => 'twitter:title', 'content' => $blog->title],
            $metaDescription ? ['name' => 'twitter:description', 'content' => $metaDescription] : null,
            $metaImage ? ['name' => 'twitter:image', 'content' => $metaImage] : null,
            $authorName ? ['name' => 'twitter:creator', 'content' => $authorName] : null,
        ])->filter()
            ->map(fn (array $attributes) => $renderTag('meta', $attributes))
            ->all();

        $linkTags = collect([
            ['rel' => 'canonical', 'href' => $canonicalUrl],
        ])->map(fn (array $attributes) => $renderTag('link', $attributes))
            ->all();

        $currentUser = $request->user();

        $isSubscribed = false;

        if ($currentUser) {
            $isSubscribed = $blog->commentSubscribers()
                ->where('users.id', $currentUser->id)
                ->exists();
        }

        return Inertia::render('BlogView', [
            'blog' => [
                'id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'cover_image' => $coverImageUrl,
                'canonical_url' => $canonicalUrl,
                'body' => $blog->body,
                'views' => $blog->views,
                'last_viewed_at' => optional($blog->last_viewed_at)->toIso8601String(),
                'published_at' => optional($blog->published_at)->toIso8601String(),
                'user' => $this->transformAuthor($blog->user),
                'categories' => $blog->categories->map(function (BlogCategory $category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ];
                })->values()->all(),
                'tags' => $blog->tags->map(function (BlogTag $tag) {
                    return [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ];
                })->values()->all(),
                'recommendations' => $recommendations,
                'comments' => $paginatedComments,
                'comment_subscription' => [
                    'is_subscribed' => $isSubscribed,
                    'subscribers_count' => $blog->comment_subscribers_count,
                ],
            ],
            'comments' => $paginatedComments,
        ])->withViewData([
            'metaTags' => $metaTags,
            'linkTags' => $linkTags,
        ]);
    }

    protected function registerBlogView(Request $request, Blog $blog): void
    {
        $session = $request->session();
        $sessionKey = 'blog_views';
        $viewRecords = $session->get($sessionKey, []);

        $record = is_array($viewRecords) ? ($viewRecords[$blog->id] ?? null) : null;
        $ipAddress = (string) ($request->ip() ?? '');
        $now = Carbon::now();
        $cooldownThreshold = $now->copy()->subMinutes(5);

        $shouldIncrement = true;

        if (is_array($record)) {
            $timestamp = isset($record['timestamp']) && is_string($record['timestamp'])
                ? Carbon::make($record['timestamp'])
                : null;
            $recordedIp = isset($record['ip']) && is_string($record['ip'])
                ? $record['ip']
                : '';

            if ($timestamp && $timestamp->greaterThan($cooldownThreshold) && $recordedIp === $ipAddress) {
                $shouldIncrement = false;
            }
        }

        if (! $shouldIncrement) {
            return;
        }

        $blog->increment('views', 1, ['last_viewed_at' => $now]);
        $blog->views = ($blog->views ?? 0) + 1;
        $blog->last_viewed_at = $now;

        if (! is_array($viewRecords)) {
            $viewRecords = [];
        }

        $viewRecords[$blog->id] = [
            'timestamp' => $now->toIso8601String(),
            'ip' => $ipAddress,
        ];

        $session->put($sessionKey, $viewRecords);
    }

    public function preview(Blog $blog, string $token): Response
    {
        abort_unless($blog->preview_token && hash_equals($blog->preview_token, $token), 403);

        $blog->load([
            'user:id,nickname,avatar_url,profile_bio,social_links',
            'categories:id,name,slug',
            'tags:id,name,slug',
        ]);

        return Inertia::render('BlogPreview', [
            'blog' => [
                'id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'cover_image' => $blog->cover_image
                    ? Storage::disk('public')->url($blog->cover_image)
                    : null,
                'body' => $blog->body,
                'published_at' => optional($blog->published_at)->toIso8601String(),
                'scheduled_for' => optional($blog->scheduled_for)->toIso8601String(),
                'status' => $blog->status,
                'user' => $this->transformAuthor($blog->user),
                'categories' => $blog->categories->map(function (BlogCategory $category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ];
                })->values()->all(),
                'tags' => $blog->tags->map(function (BlogTag $tag) {
                    return [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ];
                })->values()->all(),
            ],
        ]);
    }

    private function transformAuthor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $avatarUrl = is_string($user->avatar_url) ? trim($user->avatar_url) : null;
        $bio = is_string($user->profile_bio) ? trim($user->profile_bio) : null;

        $socialLinks = collect($user->social_links ?? [])
            ->map(function ($link) {
                if (! is_array($link)) {
                    return null;
                }

                $label = isset($link['label']) && is_string($link['label'])
                    ? trim($link['label'])
                    : null;
                $url = isset($link['url']) && is_string($link['url'])
                    ? trim($link['url'])
                    : null;

                if (! $label || ! $url) {
                    return null;
                }

                return [
                    'label' => $label,
                    'url' => $url,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $user->id,
            'nickname' => $user->nickname,
            'avatar_url' => $avatarUrl ?: null,
            'profile_bio' => $bio ?: null,
            'social_links' => $socialLinks,
        ];
    }
}
