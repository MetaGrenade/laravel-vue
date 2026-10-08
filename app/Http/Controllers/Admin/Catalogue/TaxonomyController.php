<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\TaxonomyRequest;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brands, categories and tags: the ways products are grouped in the shop. They are alike, so
 * one controller serves all three; the route's {type} says which.
 */
class TaxonomyController extends Controller
{
    /**
     * @var array<string, array{model: class-string<Model>, label: string}>
     */
    private const TYPES = [
        'brands' => ['model' => Brand::class, 'label' => 'brand'],
        'categories' => ['model' => ProductCategory::class, 'label' => 'category'],
        'tags' => ['model' => ProductTag::class, 'label' => 'tag'],
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $group = fn (string $type) => (self::TYPES[$type]['model'])::query()
            ->withCount('products')
            ->orderBy('name')
            ->get()
            ->map(fn (Model $entry) => [
                'id' => $entry->getKey(),
                'name' => $entry->getAttribute('name'),
                'slug' => $entry->getAttribute('slug'),
                'description' => $entry->getAttribute('description'),
                'products_count' => $entry->getAttribute('products_count'),
            ])
            ->values();

        return Inertia::render('acp/CommerceTaxonomy', [
            'brands' => $group('brands'),
            'categories' => $group('categories'),
            'tags' => $group('tags'),
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
        ]);
    }

    public function store(TaxonomyRequest $request, string $type): RedirectResponse
    {
        $validated = $request->validated();
        $model = self::TYPES[$type]['model'];

        $model::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? $this->uniqueSlug($model, $validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('success', ucfirst(self::TYPES[$type]['label']).' added.');
    }

    public function update(TaxonomyRequest $request, string $type, int $id): RedirectResponse
    {
        $validated = $request->validated();
        $entry = (self::TYPES[$type]['model'])::query()->findOrFail($id);

        $entry->update([
            'name' => $validated['name'],
            // Left empty, the address stays as it is.
            'slug' => $validated['slug'] ?? $entry->getAttribute('slug'),
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('success', ucfirst(self::TYPES[$type]['label']).' saved.');
    }

    /**
     * Products keep existing: a brand is cleared from them, and a category or tag link is removed.
     */
    public function destroy(string $type, int $id): RedirectResponse
    {
        (self::TYPES[$type]['model'])::query()->findOrFail($id)->delete();

        return back()->with('success', ucfirst(self::TYPES[$type]['label']).' deleted.');
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function uniqueSlug(string $model, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $suffix = 1;

        while ($model::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
