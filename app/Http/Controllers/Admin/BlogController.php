<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogRequest;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
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
                    'scheduled_for' => optional($blog->scheduled_for)->toIso8601String(),
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
            'scheduled' => (clone $blogQuery)->where('status', 'scheduled')->count(),
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
        return inertia('acp/BlogCreate', [
            'categories' => BlogCategory::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (BlogCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])
                ->values()
                ->all(),
            'tags' => BlogTag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (BlogTag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])
                ->values()
                ->all(),
            'author' => $this->authorPayload(auth()->user()),
        ]);
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

        $excerpt = $validated['excerpt'] ?? null;
        if ($excerpt === '') {
            $excerpt = null;
        }

        // Create a new blog record
        $status = $validated['status'];

        $scheduledFor = null;
        $publishedAt = null;

        if ($status === 'scheduled') {
            $scheduledValue = $validated['scheduled_for'] ?? null;
            if ($scheduledValue) {
                $scheduledCandidate = Carbon::parse($scheduledValue);

                if ($scheduledCandidate->isPast()) {
                    $status = 'published';
                    $publishedAt = $scheduledCandidate;
                } else {
                    $scheduledFor = $scheduledCandidate;
                }
            } else {
                $status = 'draft';
            }
        } elseif ($status === 'published') {
            $publishedAt = now();
        }

        $blog = Blog::create([
            'title'        => $validated['title'],
            'slug'         => Str::slug($validated['title']),
            'excerpt'      => $excerpt,
            'cover_image'  => $coverImagePath,
            'body'         => $validated['body'],
            'user_id'      => auth()->id(),
            'status'       => $status,
            'published_at' => $publishedAt,
            'scheduled_for' => $scheduledFor,
            'preview_token' => Str::uuid()->toString(),
        ]);

        $blog->categories()->sync($validated['category_ids'] ?? []);
        $blog->tags()->sync($validated['tag_ids'] ?? []);

        if (array_key_exists('author', $validated)) {
            $this->updateAuthorProfile($request->user(), $validated['author']);
        }

        return redirect()->route('acp.blogs.index')
            ->with('success', 'Blog post created successfully.');
    }

    /**
     * Show the form for editing an existing blog post.
     */
    public function edit(Blog $blog)
    {
        $blog->load(['categories:id,name,slug', 'tags:id,name,slug', 'user:id,nickname,avatar_url,profile_bio,social_links']);

        if (!$blog->preview_token) {
            $blog->forceFill([
                'preview_token' => Str::uuid()->toString(),
            ])->save();
        }

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
                'scheduled_for',
                'preview_token',
            ]), [
                'cover_image_url' => $blog->cover_image
                    ? Storage::disk('public')->url($blog->cover_image)
                    : null,
                'preview_url' => $blog->preview_token
                    ? route('blogs.preview', ['blog' => $blog->id, 'token' => $blog->preview_token])
                    : null,
                'categories' => $blog->categories
                    ->map(fn (BlogCategory $category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ])
                    ->all(),
                'tags' => $blog->tags
                    ->map(fn (BlogTag $tag) => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ])
                    ->all(),
                'user' => $this->authorPayload($blog->user),
            ]),
            'categories' => BlogCategory::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (BlogCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])
                ->values()
                ->all(),
            'tags' => BlogTag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (BlogTag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Update an existing blog post in storage.
     */
    public function update(BlogRequest $request, Blog $blog)
    {
        // Validate request data
        $validated = $request->validated();

        $excerpt = $validated['excerpt'] ?? null;
        if ($excerpt === '') {
            $excerpt = null;
        }

        $status = $validated['status'];

        $scheduledFor = null;
        $publishedAt = $blog->published_at;

        if ($status === 'scheduled') {
            $scheduledValue = $validated['scheduled_for'] ?? null;
            if ($scheduledValue) {
                $scheduledCandidate = Carbon::parse($scheduledValue);

                if ($scheduledCandidate->isPast()) {
                    $status = 'published';
                    $publishedAt = $scheduledCandidate;
                    $scheduledFor = null;
                } else {
                    $scheduledFor = $scheduledCandidate;
                    $publishedAt = null;
                }
            } else {
                $status = 'draft';
                $publishedAt = null;
            }
        } elseif ($status === 'published') {
            $publishedAt = now();
        } else {
            $publishedAt = null;
        }

        $updateData = [
            'title'        => $validated['title'],
            'slug'         => Str::slug($validated['title']),
            'excerpt'      => $excerpt,
            'body'         => $validated['body'],
            'status'       => $status,
            'published_at' => $publishedAt,
            'scheduled_for' => $scheduledFor,
        ];

        if ($request->hasFile('cover_image')) {
            if ($blog->cover_image) {
                Storage::disk('public')->delete($blog->cover_image);
            }

            $updateData['cover_image'] = $request->file('cover_image')->store('blog-covers', 'public');
        }

        // Update the blog record
        $blog->update($updateData);

        $blog->categories()->sync($validated['category_ids'] ?? []);
        $blog->tags()->sync($validated['tag_ids'] ?? []);

        if (array_key_exists('author', $validated)) {
            $blog->loadMissing('user');
            $this->updateAuthorProfile($blog->user, $validated['author']);
        }

        return redirect()->route('acp.blogs.index')
            ->with('success', 'Blog post updated successfully.');
    }

    private function authorPayload(?User $user): ?array
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

    private function sanitizeAuthorInput($input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $payload = [];

        if (array_key_exists('avatar_url', $input)) {
            $avatar = is_string($input['avatar_url']) ? trim($input['avatar_url']) : '';
            $payload['avatar_url'] = $avatar !== '' ? $avatar : null;
        }

        if (array_key_exists('profile_bio', $input)) {
            $bio = is_string($input['profile_bio']) ? trim($input['profile_bio']) : '';
            $payload['profile_bio'] = $bio !== '' ? $bio : null;
        }

        if (array_key_exists('social_links', $input)) {
            $links = collect(is_array($input['social_links']) ? $input['social_links'] : [])
                ->map(function ($link) {
                    if (! is_array($link)) {
                        return null;
                    }

                    $label = isset($link['label']) && is_string($link['label'])
                        ? trim($link['label'])
                        : '';
                    $url = isset($link['url']) && is_string($link['url'])
                        ? trim($link['url'])
                        : '';

                    if ($label === '' || $url === '') {
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

            $payload['social_links'] = $links;
        }

        return $payload;
    }

    private function updateAuthorProfile(?User $author, $input): void
    {
        if (! $author) {
            return;
        }

        $payload = $this->sanitizeAuthorInput($input);

        if ($payload === []) {
            return;
        }

        $author->forceFill($payload)->save();
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
                'scheduled_for' => null,
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
                'scheduled_for' => null,
            ])->save();
        }

        return redirect()->back()->with('success', 'Blog post moved back to draft.');
    }

    /**
     * Archive the specified blog post.
     */
    public function archive(Blog $blog): RedirectResponse
    {
        if ($blog->status !== 'archived') {
            $blog->forceFill([
                'status' => 'archived',
                'published_at' => null,
                'scheduled_for' => null,
            ])->save();
        }

        return redirect()->back()->with('success', 'Blog post archived successfully.');
    }

    /**
     * Restore an archived blog post back to draft state.
     */
    public function unarchive(Blog $blog): RedirectResponse
    {
        if ($blog->status === 'archived') {
            $blog->forceFill([
                'status' => 'draft',
                'published_at' => null,
                'scheduled_for' => null,
            ])->save();
        }

        return redirect()->back()->with('success', 'Blog post unarchived successfully.');
    }
}
