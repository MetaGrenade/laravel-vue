<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        $blogsQuery = Blog::query()
            ->where('status', 'published')
            ->with([
                'user:id,nickname',
                'categories:id,name,slug',
                'tags:id,name,slug',
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('created_at');

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
        })->values();

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
            'comments' => function ($query) {
                $query->with(['user:id,nickname'])->orderBy('created_at');
            },
        ])
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return Inertia::render('BlogView', [
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
                'comments' => $blog->comments->map(function ($comment) {
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
                })->values(),
            ],
        ]);
    }
}
