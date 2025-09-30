<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\Blog;
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
                'cover_image' => $blog->cover_image
                    ? Storage::disk('public')->url($blog->cover_image)
                    : null,
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
        $blog = Blog::with([
            'user:id,nickname',
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
