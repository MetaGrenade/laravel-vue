<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Blog;

class BlogController extends Controller
{
    /**
     * Display a listing of published blog posts.
     */
    public function index(Request $request)
    {
        $blogs = Blog::where('status', 'published')
            ->orderBy('published_at', 'desc')
            ->paginate(10);

        // Return an Inertia response for SPA or a normal view
        return inertia('Blog', compact('blogs'));
    }

    /**
     * Show the detailed view for a single blog post.
     */
    public function show($slug)
    {
        $blog = Blog::where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return inertia('BlogView', [
            'blog' => $blog,
        ]);
    }
}
