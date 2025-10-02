<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
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
                'user:id,nickname',
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
                'published_at' => optional($blog->published_at)->toIso8601String(),
                'author' => $blog->user ? [
                    'id' => $blog->user->id,
                    'nickname' => $blog->user->nickname,
                ] : null,
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
     * Show the detailed view for a single blog post.
     */
    public function show($slug): Response
    {
        $blog = Blog::with([
            'user:id,nickname',
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

        if ($blog->status === 'scheduled' && $blog->scheduled_for && $blog->scheduled_for->lessThanOrEqualTo(now())) {
            $blog->forceFill([
                'status' => 'published',
                'published_at' => $blog->scheduled_for,
                'scheduled_for' => null,
            ])->save();
        }

        $comments = $blog->comments()
            ->with(['user:id,nickname'])
            ->orderBy('created_at')
            ->paginate(10, ['*'], 'page', 1);

        $commentItems = $comments->getCollection()
            ->map(function (BlogComment $comment) {
                return [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'created_at' => optional($comment->created_at)->toIso8601String(),
                    'updated_at' => optional($comment->updated_at)->toIso8601String(),
                    'user' => $comment->user ? [
                        'id' => $comment->user->id,
                        'nickname' => $comment->user->nickname,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $coverImageUrl = $blog->cover_image
            ? Storage::disk('public')->url($blog->cover_image)
            : null;

        $canonicalUrl = route('blogs.view', ['slug' => $blog->slug]);
        $metaDescription = $blog->excerpt ? trim($blog->excerpt) : null;
        $metaImage = $coverImageUrl;
        $authorName = $blog->user && $blog->user->nickname
            ? trim($blog->user->nickname)
            : null;

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

        return Inertia::render('BlogView', [
            'blog' => [
                'id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'cover_image' => $coverImageUrl,
                'canonical_url' => $canonicalUrl,
                'body' => $blog->body,
                'published_at' => optional($blog->published_at)->toIso8601String(),
                'user' => $blog->user ? [
                    'id' => $blog->user->id,
                    'nickname' => $blog->user->nickname,
                ] : null,
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
                'comments' => array_merge([
                    'data' => $commentItems,
                ], $this->inertiaPagination($comments)),
            ],
        ])->withViewData([
            'metaTags' => $metaTags,
            'linkTags' => $linkTags,
        ]);
    }

    public function preview(Blog $blog, string $token): Response
    {
        abort_unless($blog->preview_token && hash_equals($blog->preview_token, $token), 403);

        $blog->load([
            'user:id,nickname',
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
                'user' => $blog->user ? [
                    'id' => $blog->user->id,
                    'nickname' => $blog->user->nickname,
                ] : null,
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
}
