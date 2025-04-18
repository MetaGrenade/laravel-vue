<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogRequest;
use Illuminate\Http\Request;
use App\Models\Blog;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    /**
     * Display a listing of all blog posts for management.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);

        // Retrieve blogs with their associated author information.
        $blogs = Blog::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $blogStats = [
            'total'      => Blog::count(),
            'published'  => Blog::where('status', 'published')->count(),
            'draft'      => Blog::where('status', 'draft')->count(),
            'archived'   => Blog::where('status', 'archived')->count(),
        ];

        return inertia('acp/Blogs', compact('blogs', 'blogStats'));
    }

    /**
     * Show the form for creating a new blog post.
     */
    public function create()
    {
        return inertia('acp/BlogCreate');
    }

    /**
     * Store a newly created blog post in storage.
     */
    public function store(BlogRequest $request)
    {
        // Validate request data
        $validated = $request->validated();

        // Create a new blog record
        $blog = Blog::create([
            'title'        => $validated['title'],
            'slug'         => Str::slug($validated['title']),
            'excerpt'      => $validated['excerpt'] ?? null,
            'body'         => $validated['body'],
            'user_id'      => auth()->id(),
            'status'       => $validated['status'],
            'published_at' => $validated['status'] === 'published' ? now() : null,
        ]);

        return redirect()->route('acp.blogs.index')
            ->with('success', 'Blog post created successfully.');
    }

    /**
     * Show the form for editing an existing blog post.
     */
    public function edit(Blog $blog)
    {
        return inertia('acp/BlogEdit', [
            'blog' => $blog,
        ]);
    }

    /**
     * Update an existing blog post in storage.
     */
    public function update(BlogRequest $request, Blog $blog)
    {
        // Validate request data
        $validated = $request->validated();

        // Update the blog record
        $blog->update([
            'title'        => $validated['title'],
            'slug'         => Str::slug($validated['title']),
            'excerpt'      => $validated['excerpt'] ?? null,
            'body'         => $validated['body'],
            'status'       => $validated['status'],
            'published_at' => $validated['status'] === 'published' ? now() : $blog->published_at,
        ]);

        return redirect()->route('acp.blogs.index')
            ->with('success', 'Blog post updated successfully.');
    }

    /**
     * Remove a blog post from storage.
     */
    public function destroy(Blog $blog)
    {
        $blog->delete();

        return redirect()->route('acp.blogs.index')
            ->with('success', 'Blog post deleted successfully.');
    }
}
