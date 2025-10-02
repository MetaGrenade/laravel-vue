<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBlogCategoryRequest;
use App\Http\Requests\Admin\UpdateBlogCategoryRequest;
use App\Models\BlogCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Response;

class BlogCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $categoryQuery = BlogCategory::query()->orderBy('name');

        if ($request->expectsJson()) {
            $categories = $categoryQuery
                ->get(['id', 'name', 'slug'])
                ->map(fn (BlogCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])
                ->values()
                ->all();

            return response()->json([
                'data' => $categories,
            ]);
        }

        $categories = (clone $categoryQuery)
            ->withCount('blogs')
            ->get()
            ->map(fn (BlogCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'blogs_count' => $category->blogs_count,
                'created_at' => optional($category->created_at)->toIso8601String(),
                'updated_at' => optional($category->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        return inertia('acp/BlogCategories', [
            'categories' => $categories,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return inertia('acp/BlogCategoryCreate');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBlogCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $slug = $validated['slug'] ?? null;
        if (!$slug) {
            $slug = Str::slug($validated['name']);
        }

        BlogCategory::create([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        return redirect()
            ->route('acp.blog-categories.index')
            ->with('success', 'Blog category created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BlogCategory $category): Response
    {
        return inertia('acp/BlogCategoryEdit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBlogCategoryRequest $request, BlogCategory $category): RedirectResponse
    {
        $validated = $request->validated();

        $slug = Arr::get($validated, 'slug');
        if (!$slug) {
            $slug = Str::slug($validated['name']);
        }

        $category->update([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        return redirect()
            ->route('acp.blog-categories.edit', ['category' => $category->id])
            ->with('success', 'Blog category updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BlogCategory $category): RedirectResponse
    {
        $category->delete();

        return redirect()
            ->route('acp.blog-categories.index')
            ->with('success', 'Blog category deleted successfully.');
    }
}
