<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogRequest;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogTag;
use App\Models\BlogRevision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        $search = trim((string) $request->query('search', ''));
        $sort = strtolower((string) $request->query('sort', 'created_desc'));
        $allowedSorts = ['created_desc', 'created_asc', 'views_desc', 'views_asc'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'created_desc';
        }

        $minViewsInput = $request->query('min_views');
        $maxViewsInput = $request->query('max_views');

        $minViews = is_numeric($minViewsInput) ? max(0, (int) $minViewsInput) : null;
        $maxViews = is_numeric($maxViewsInput) ? max(0, (int) $maxViewsInput) : null;

        if ($minViews !== null && $maxViews !== null && $maxViews < $minViews) {
            $maxViews = null;
        }
        $statusFilters = collect(Arr::wrap($request->input('status')))
            ->flatMap(function ($value) {
                if (is_string($value) && str_contains($value, ',')) {
                    return collect(explode(',', $value))->map(fn ($item) => trim($item))->all();
                }

                return [$value];
            })
            ->filter(fn ($value) => is_string($value))
            ->map(fn ($value) => strtolower($value))
            ->filter(fn ($value) => in_array($value, ['draft', 'published', 'scheduled', 'archived'], true))
            ->unique()
            ->values()
            ->all();

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

        $baseQuery = Blog::query();
        $filteredQuery = (clone $baseQuery);

        if ($search !== '') {
            $filteredQuery->where(function ($query) use ($search) {
                $like = "%{$search}%";

                $query
                    ->where('title', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('user', function ($userQuery) use ($like) {
                        $userQuery
                            ->where('nickname', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            });
        }

        if (!empty($statusFilters)) {
            $filteredQuery->whereIn('status', $statusFilters);
        }

        if ($minViews !== null) {
            $filteredQuery->where('views', '>=', $minViews);
        }

        if ($maxViews !== null) {
            $filteredQuery->where('views', '<=', $maxViews);
        }

        $orderedQuery = (clone $filteredQuery);

        switch ($sort) {
            case 'created_asc':
                $orderedQuery->orderBy('created_at');
                break;
            case 'views_desc':
                $orderedQuery->orderByDesc('views')->orderByDesc('created_at');
                break;
            case 'views_asc':
                $orderedQuery->orderBy('views')->orderByDesc('created_at');
                break;
            default:
                $orderedQuery->orderByDesc('created_at');
                break;
        }

        // Retrieve blogs with their associated author information.
        $blogs = $orderedQuery
            ->with(['user:id,nickname,email'])
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
                    'views' => $blog->views,
                    'last_viewed_at' => optional($blog->last_viewed_at)->toIso8601String(),
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
            'total' => (clone $baseQuery)->count(),
            'published' => (clone $baseQuery)->where('status', 'published')->count(),
            'draft' => (clone $baseQuery)->where('status', 'draft')->count(),
            'scheduled' => (clone $baseQuery)->where('status', 'scheduled')->count(),
            'archived' => (clone $baseQuery)->where('status', 'archived')->count(),
            'total_views' => (clone $baseQuery)->sum('views'),
            'viewed_last_30_days' => (clone $baseQuery)
                ->whereNotNull('last_viewed_at')
                ->where('last_viewed_at', '>=', Carbon::now()->subDays(30))
                ->count(),
        ];

        $trendingWindow = Carbon::now()->subDays(30);

        $trendingPosts = Blog::query()
            ->where('status', 'published')
            ->where('views', '>', 0)
            ->whereNotNull('last_viewed_at')
            ->where('last_viewed_at', '>=', $trendingWindow)
            ->orderByDesc('views')
            ->orderByDesc('last_viewed_at')
            ->limit(7)
            ->get(['id', 'title', 'slug', 'views', 'last_viewed_at', 'published_at']);

        if ($trendingPosts->isEmpty()) {
            $trendingPosts = Blog::query()
                ->where('status', 'published')
                ->where('views', '>', 0)
                ->orderByDesc('views')
                ->orderByDesc('last_viewed_at')
                ->limit(7)
                ->get(['id', 'title', 'slug', 'views', 'last_viewed_at', 'published_at']);
        }

        $trendingPostsPayload = $trendingPosts
            ->map(function (Blog $post) {
                return [
                    'id' => $post->id,
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'views' => $post->views,
                    'last_viewed_at' => optional($post->last_viewed_at)->toIso8601String(),
                    'published_at' => optional($post->published_at)->toIso8601String(),
                    'label' => Str::limit($post->title, 40),
                ];
            })
            ->values()
            ->all();

        return inertia('acp/Blogs', [
            'blogs' => array_merge([
                'data' => $blogItems,
            ], $this->inertiaPagination($blogs)),
            'blogStats' => $blogStats,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => !empty($statusFilters) ? $statusFilters : null,
                'sort' => $sort !== 'created_desc' ? $sort : null,
                'min_views' => $minViews,
                'max_views' => $maxViews,
            ],
            'trendingPosts' => $trendingPostsPayload,
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

        $blog->refresh();
        $blog->load('categories:id', 'tags:id');

        BlogRevision::recordSnapshot($blog, $request->user());

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
            'permissions' => [
                'canViewRevisions' => request()->user()?->can('viewRevisions', $blog) ?? false,
                'canRestoreRevisions' => request()->user()?->can('restoreRevision', $blog) ?? false,
            ],
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

        $blog->refresh();
        $blog->load('categories:id', 'tags:id');

        BlogRevision::recordSnapshot($blog, $request->user());

        return redirect()->route('acp.blogs.index')
            ->with('success', 'Blog post updated successfully.');
    }

    public function revisions(Blog $blog): Response
    {
        $this->authorize('viewRevisions', $blog);

        $blog->load([
            'user:id,nickname',
            'categories:id,name,slug',
            'tags:id,name,slug',
        ]);

        $revisionModels = $blog->revisions()
            ->with('editor:id,nickname')
            ->orderByDesc('created_at')
            ->get();

        $categoryIds = $revisionModels
            ->flatMap(fn (BlogRevision $revision) => (array) ($revision->category_ids ?? []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $tagIds = $revisionModels
            ->flatMap(fn (BlogRevision $revision) => (array) ($revision->tag_ids ?? []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $categoryMap = BlogCategory::query()
            ->whereIn('id', $categoryIds)
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        $tagMap = BlogTag::query()
            ->whereIn('id', $tagIds)
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        $revisions = $revisionModels
            ->map(function (BlogRevision $revision) use ($categoryMap, $tagMap) {
                $categories = collect($revision->category_ids ?? [])
                    ->map(function ($id) use ($categoryMap) {
                        $category = $categoryMap->get($id);

                        if (! $category) {
                            return null;
                        }

                        return [
                            'id' => $category->id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                $tags = collect($revision->tag_ids ?? [])
                    ->map(function ($id) use ($tagMap) {
                        $tag = $tagMap->get($id);

                        if (! $tag) {
                            return null;
                        }

                        return [
                            'id' => $tag->id,
                            'name' => $tag->name,
                            'slug' => $tag->slug,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $revision->id,
                    'title' => $revision->title,
                    'slug' => $revision->slug,
                    'excerpt' => $revision->excerpt,
                    'body' => $revision->body,
                    'cover_image' => $revision->cover_image,
                    'cover_image_url' => $revision->cover_image
                        ? Storage::disk('public')->url($revision->cover_image)
                        : null,
                    'status' => $revision->status,
                    'published_at' => optional($revision->published_at)?->toIso8601String(),
                    'scheduled_for' => optional($revision->scheduled_for)?->toIso8601String(),
                    'edited_at' => optional($revision->edited_at)?->toIso8601String(),
                    'created_at' => optional($revision->created_at)?->toIso8601String(),
                    'category_ids' => $revision->category_ids ?? [],
                    'tag_ids' => $revision->tag_ids ?? [],
                    'categories' => $categories,
                    'tags' => $tags,
                    'metadata' => $revision->metadata ?? [],
                    'editor' => $revision->editor
                        ? [
                            'id' => $revision->editor->id,
                            'nickname' => $revision->editor->nickname,
                        ]
                        : null,
                ];
            })
            ->values()
            ->all();

        return inertia('acp/BlogRevisionHistory', [
            'blog' => [
                'id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'body' => $blog->body,
                'status' => $blog->status,
                'cover_image' => $blog->cover_image,
                'cover_image_url' => $blog->cover_image
                    ? Storage::disk('public')->url($blog->cover_image)
                    : null,
                'created_at' => optional($blog->created_at)?->toIso8601String(),
                'updated_at' => optional($blog->updated_at)?->toIso8601String(),
                'published_at' => optional($blog->published_at)?->toIso8601String(),
                'scheduled_for' => optional($blog->scheduled_for)?->toIso8601String(),
                'metadata' => [
                    'views' => $blog->views,
                    'last_viewed_at' => optional($blog->last_viewed_at)?->toIso8601String(),
                    'preview_token' => $blog->preview_token,
                ],
                'author' => $blog->user
                    ? [
                        'id' => $blog->user->id,
                        'nickname' => $blog->user->nickname,
                    ]
                    : null,
                'categories' => $blog->categories
                    ->map(fn (BlogCategory $category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ])
                    ->values()
                    ->all(),
                'tags' => $blog->tags
                    ->map(fn (BlogTag $tag) => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ])
                    ->values()
                    ->all(),
            ],
            'permissions' => [
                'canRestore' => request()->user()?->can('restoreRevision', $blog) ?? false,
            ],
            'revisions' => $revisions,
        ]);
    }

    public function restoreRevision(Request $request, Blog $blog, BlogRevision $revision): RedirectResponse
    {
        $this->authorize('restoreRevision', $blog);

        if ($revision->blog_id !== $blog->id) {
            abort(404);
        }

        BlogRevision::recordSnapshot($blog->fresh(['categories:id', 'tags:id']), $request->user());

        $metadata = is_array($revision->metadata) ? $revision->metadata : [];
        $excerpt = $revision->excerpt ?? null;

        $blog->forceFill([
            'title' => $revision->title,
            'slug' => $revision->slug,
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'body' => $revision->body,
            'cover_image' => $revision->cover_image,
            'status' => $revision->status,
            'published_at' => $revision->published_at,
            'scheduled_for' => $revision->scheduled_for,
        ]);

        $previewToken = Arr::get($metadata, 'preview_token');
        if (is_string($previewToken) && $previewToken !== '') {
            $blog->preview_token = $previewToken;
        }

        $blog->save();

        $blog->categories()->sync($revision->category_ids ?? []);
        $blog->tags()->sync($revision->tag_ids ?? []);

        $blog->refresh();
        $blog->load('categories:id', 'tags:id');

        BlogRevision::recordSnapshot($blog, $request->user());

        return redirect()
            ->route('acp.blogs.revisions.index', ['blog' => $blog->id])
            ->with('success', 'Blog revision restored successfully.');
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

            $blog->refresh();
            $blog->load('categories:id', 'tags:id');

            BlogRevision::recordSnapshot($blog, request()->user());
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

            $blog->refresh();
            $blog->load('categories:id', 'tags:id');

            BlogRevision::recordSnapshot($blog, request()->user());
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

            $blog->refresh();
            $blog->load('categories:id', 'tags:id');

            BlogRevision::recordSnapshot($blog, request()->user());
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

            $blog->refresh();
            $blog->load('categories:id', 'tags:id');

            BlogRevision::recordSnapshot($blog, request()->user());
        }

        return redirect()->back()->with('success', 'Blog post unarchived successfully.');
    }
}
