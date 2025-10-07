<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogCategoryRequest;
use App\Models\BlogCategory;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class BlogCategoryController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $formatter = DateFormatter::for($request->user());

        $categories = BlogCategory::query()
            ->withCount('blogs')
            ->orderBy('name')
            ->get()
            ->map(fn (BlogCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'blogs_count' => $category->blogs_count ?? 0,
                'created_at' => $formatter->iso($category->created_at),
                'updated_at' => $formatter->iso($category->updated_at),
            ])
            ->values()
            ->all();

        if ($request->wantsJson()) {
            return response()->json(['categories' => $categories]);
        }

        return inertia('acp/BlogCategories', [
            'categories' => $categories,
        ]);
    }

    public function create(): Response
    {
        return inertia('acp/BlogCategoryCreate');
    }

    public function store(BlogCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        BlogCategory::create([
            'name' => $validated['name'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['name']),
        ]);

        return redirect()
            ->route('acp.blog-categories.index')
            ->with('success', 'Category created successfully.');
    }

    public function edit(BlogCategory $category): Response
    {
        $category->loadCount('blogs');

        $formatter = DateFormatter::for(request()->user());

        return inertia('acp/BlogCategoryEdit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'created_at' => $formatter->iso($category->created_at),
                'updated_at' => $formatter->iso($category->updated_at),
                'blogs_count' => $category->blogs_count ?? 0,
            ],
        ]);
    }

    public function update(BlogCategoryRequest $request, BlogCategory $category): RedirectResponse
    {
        $validated = $request->validated();

        $category->forceFill([
            'name' => $validated['name'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['name'], $category->id),
        ])->save();

        return redirect()
            ->route('acp.blog-categories.index')
            ->with('success', 'Category updated successfully.');
    }

    public function destroy(BlogCategory $category): RedirectResponse
    {
        $category->delete();

        return redirect()
            ->route('acp.blog-categories.index')
            ->with('success', 'Category deleted successfully.');
    }

    protected function resolveSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $candidate = Str::slug($slug ?: $name);

        if ($candidate === '') {
            $candidate = Str::random(8);
        }

        $original = $candidate;
        $suffix = 1;

        $query = BlogCategory::query()->where('slug', $candidate);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->exists()) {
            $candidate = $original.'-'.$suffix++;

            $query = BlogCategory::query()->where('slug', $candidate);

            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
        }

        return $candidate;
    }
}
