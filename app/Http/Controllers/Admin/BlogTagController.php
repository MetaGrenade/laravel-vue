<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogTagRequest;
use App\Models\BlogTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class BlogTagController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $tags = BlogTag::query()
            ->withCount('blogs')
            ->orderBy('name')
            ->get()
            ->map(fn (BlogTag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'blogs_count' => $tag->blogs_count ?? 0,
                'created_at' => optional($tag->created_at)->toIso8601String(),
                'updated_at' => optional($tag->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($request->wantsJson()) {
            return response()->json(['tags' => $tags]);
        }

        return inertia('acp/BlogTags', [
            'tags' => $tags,
        ]);
    }

    public function create(): Response
    {
        return inertia('acp/BlogTagCreate');
    }

    public function store(BlogTagRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        BlogTag::create([
            'name' => $validated['name'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['name']),
        ]);

        return redirect()
            ->route('acp.blog-tags.index')
            ->with('success', 'Tag created successfully.');
    }

    public function edit(BlogTag $tag): Response
    {
        $tag->loadCount('blogs');

        return inertia('acp/BlogTagEdit', [
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'created_at' => optional($tag->created_at)->toIso8601String(),
                'updated_at' => optional($tag->updated_at)->toIso8601String(),
                'blogs_count' => $tag->blogs_count ?? 0,
            ],
        ]);
    }

    public function update(BlogTagRequest $request, BlogTag $tag): RedirectResponse
    {
        $validated = $request->validated();

        $tag->forceFill([
            'name' => $validated['name'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['name'], $tag->id),
        ])->save();

        return redirect()
            ->route('acp.blog-tags.index')
            ->with('success', 'Tag updated successfully.');
    }

    public function destroy(BlogTag $tag): RedirectResponse
    {
        $tag->delete();

        return redirect()
            ->route('acp.blog-tags.index')
            ->with('success', 'Tag deleted successfully.');
    }

    protected function resolveSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $candidate = Str::slug($slug ?: $name);

        if ($candidate === '') {
            $candidate = Str::random(8);
        }

        $original = $candidate;
        $suffix = 1;

        $query = BlogTag::query()->where('slug', $candidate);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->exists()) {
            $candidate = $original.'-'.$suffix++;

            $query = BlogTag::query()->where('slug', $candidate);

            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
        }

        return $candidate;
    }
}
