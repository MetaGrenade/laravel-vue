<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\Blog;
use Illuminate\Http\Request;
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
        $blogs = Blog::query()
            ->where('status', 'published')
            ->with(['user:id,nickname'])
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->paginate(9)
            ->withQueryString();

        $blogItems = $blogs->getCollection()->map(function (Blog $blog) {
            return [
                'id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'cover_image' => $blog->cover_image ?? null,
                'published_at' => optional($blog->published_at)->toIso8601String(),
                'author' => $blog->user ? [
                    'id' => $blog->user->id,
                    'nickname' => $blog->user->nickname,
                ] : null,
            ];
        })->values();

        return Inertia::render('Blog', [
            'blogs' => array_merge([
                'data' => $blogItems,
            ], $this->inertiaPagination($blogs)),
        ]);
    }

    /**
     * Show the detailed view for a single blog post.
     */
    public function show($slug): Response
    {
        $blog = Blog::with(['user:id,nickname'])
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return Inertia::render('BlogView', [
            'blog' => [
                'id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'body' => $blog->body,
                'published_at' => optional($blog->published_at)->toIso8601String(),
                'user' => $blog->user ? [
                    'id' => $blog->user->id,
                    'nickname' => $blog->user->nickname,
                ] : null,
            ],
        ]);
    }
}
