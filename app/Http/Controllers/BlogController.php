<?php

namespace App\Http\Controllers;

use App\Http\Resources\BlogCommentResource;
use App\Models\Blog;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public const COMMENTS_PER_PAGE = 10;

    /**
     * Display a listing of published blog posts.
     */
    public function index(Request $request)
    {
        $blogs = Blog::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->paginate(10)
            ->withQueryString();

        // Return an Inertia response for SPA or a normal view
        return inertia('Blog', compact('blogs'));
    }

    /**
     * Show the detailed view for a single blog post.
     */
    public function show(Request $request, Blog $blog)
    {
        abort_unless($blog->status === 'published', 404);

        $blog->load(['user:id,nickname,avatar']);

        $comments = $blog->comments()
            ->with(['user:id,nickname,avatar'])
            ->orderBy('created_at')
            ->paginate(self::COMMENTS_PER_PAGE)
            ->withQueryString();

        $comments->getCollection()->transform(
            fn ($comment) => BlogCommentResource::make($comment)->resolve()
        );

        return inertia('BlogView', [
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
                    'avatar' => $blog->user->avatar,
                ] : null,
            ],
            'comments' => $comments,
        ]);
    }
}
