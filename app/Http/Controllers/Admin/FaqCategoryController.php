<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FaqCategoryRequest;
use App\Models\FaqCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class FaqCategoryController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $categories = FaqCategory::query()
            ->withCount('faqs')
            ->orderBy('order')
            ->orderBy('name')
            ->get()
            ->map(fn (FaqCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'order' => $category->order,
                'faqs_count' => $category->faqs_count ?? 0,
                'created_at' => optional($category->created_at)->toIso8601String(),
                'updated_at' => optional($category->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($request->wantsJson()) {
            return response()->json(['categories' => $categories]);
        }

        return inertia('acp/SupportFaqCategories', [
            'categories' => $categories,
        ]);
    }

    public function create(): Response
    {
        return inertia('acp/SupportFaqCategoryCreate');
    }

    public function store(FaqCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        FaqCategory::create([
            'name' => $validated['name'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['name']),
            'description' => $validated['description'] ?? null,
            'order' => $validated['order'],
        ]);

        return redirect()
            ->route('acp.support.faq-categories.index')
            ->with('success', 'FAQ category created successfully.');
    }

    public function edit(FaqCategory $category): Response
    {
        $category->loadCount('faqs');

        return inertia('acp/SupportFaqCategoryEdit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'order' => $category->order,
                'faqs_count' => $category->faqs_count ?? 0,
                'created_at' => optional($category->created_at)->toIso8601String(),
                'updated_at' => optional($category->updated_at)->toIso8601String(),
            ],
        ]);
    }

    public function update(FaqCategoryRequest $request, FaqCategory $category): RedirectResponse
    {
        $validated = $request->validated();

        $category->forceFill([
            'name' => $validated['name'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['name'], $category->id),
            'description' => $validated['description'] ?? null,
            'order' => $validated['order'],
        ])->save();

        return redirect()
            ->route('acp.support.faq-categories.index')
            ->with('success', 'FAQ category updated successfully.');
    }

    public function destroy(FaqCategory $category): RedirectResponse
    {
        $category->delete();

        return redirect()
            ->route('acp.support.faq-categories.index')
            ->with('success', 'FAQ category deleted successfully.');
    }

    protected function resolveSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $candidate = Str::slug($slug ?: $name);

        if ($candidate === '') {
            $candidate = Str::random(8);
        }

        $original = $candidate;
        $suffix = 1;

        $query = FaqCategory::query()->where('slug', $candidate);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->exists()) {
            $candidate = $original.'-'.$suffix++;

            $query = FaqCategory::query()->where('slug', $candidate);

            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
        }

        return $candidate;
    }
}
