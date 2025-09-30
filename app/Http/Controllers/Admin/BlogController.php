<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogRequest;
use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;

class BlogController extends Controller
{
    use InteractsWithInertiaPagination;

    /**
     * Display a listing of all blog posts for management.
     */
    public function index(Request $request): Response
    {
        $perPage = (int) $request->get('per_page', 15);

        $blogQuery = Blog::query();

        // Retrieve blogs with their associated author information.
        $blogs = (clone $blogQuery)
            ->with(['user:id,nickname,email'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $blogItems = $blogs->getCollection()
            ->map(function (Blog $blog) {
                return [
                    'id' => $blog->id,
                    'title' => $blog->title,
                    'slug' => $blog->slug,
                    'status' => $blog->status,
                    'created_at' => optional($blog->created_at)->toIso8601String(),
                    'user' => $blog->user ? [
                        'id' => $blog->user->id,
                        'nickname' => $blog->user->nickname,
                        'email' => $blog->user->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $blogStats = [
            'total' => $blogs->total(),
            'published' => (clone $blogQuery)->where('status', 'published')->count(),
            'draft' => (clone $blogQuery)->where('status', 'draft')->count(),
            'archived' => (clone $blogQuery)->where('status', 'archived')->count(),
        ];

        return inertia('acp/Blogs', [
            'blogs' => array_merge([
                'data' => $blogItems,
            ], $this->inertiaPagination($blogs)),
            'blogStats' => $blogStats,
        ]);
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

        $coverImagePath = $request->file('cover_image')
            ? $request->file('cover_image')->store('blog-covers', 'public')
            : null;

        // Create a new blog record
        $blog = Blog::create([
            'title'        => $validated['title'],
            'slug'         => Str::slug($validated['title']),
            'excerpt'      => $validated['excerpt'] ?? null,
            'cover_image'  => $coverImagePath,
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
            'blog' => array_merge($blog->only([
                'id',
                'title',
                'slug',
                'excerpt',
                'cover_image',
                'body',
                'status',
                'created_at',
                'updated_at',
                'published_at',
            ]), [
                'cover_image_url' => $blog->cover_image
                    ? Storage::disk('public')->url($blog->cover_image)
                    : null,
            ]),
        ]);
    }

    /**
     * Update an existing blog post in storage.
     */
    public function update(BlogRequest $request, Blog $blog)
    {
        // Validate request data
        $validated = $request->validated();

        $updateData = [
            'title'        => $validated['title'],
            'slug'         => Str::slug($validated['title']),
            'excerpt'      => $validated['excerpt'] ?? null,
            'body'         => $validated['body'],
            'status'       => $validated['status'],
            'published_at' => $validated['status'] === 'published' ? now() : $blog->published_at,
        ];

        if ($request->hasFile('cover_image')) {
            if ($blog->cover_image) {
                Storage::disk('public')->delete($blog->cover_image);
            }

            $updateData['cover_image'] = $request->file('cover_image')->store('blog-covers', 'public');
        }

        // Update the blog record
        $blog->update($updateData);

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

    /**
     * Publish the specified blog post.
     */
    public function publish(Blog $blog): RedirectResponse
    {
        if ($blog->status !== 'published') {
            $blog->forceFill([
                'status' => 'published',
                'published_at' => now(),
            ])->save();
        }

        return redirect()->back()->with('success', 'Blog post published successfully.');
    }

    /**
     * Unpublish the specified blog post.
     */
    public function unpublish(Blog $blog): RedirectResponse
    {
        if ($blog->status !== 'draft') {
            $blog->forceFill([
                'status' => 'draft',
                'published_at' => null,
            ])->save();
        }

        return redirect()->back()->with('success', 'Blog post moved back to draft.');
    }
}
