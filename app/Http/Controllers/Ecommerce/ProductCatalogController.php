<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use App\Support\Seo\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'array'],
            'category.*' => ['integer'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
        ]);

        $products = Product::query()
            ->with(['variants.prices', 'prices', 'categories:id,name,slug', 'tags:id,name,slug', 'brand:id,name,slug'])
            ->where('is_active', true)
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['category'] ?? null, function ($query, array $categories) {
                $query->whereHas('categories', function ($query) use ($categories) {
                    $query->whereIn('product_categories.id', $categories);
                });
            })
            ->when($filters['tags'] ?? null, function ($query, array $tags) {
                $query->whereHas('tags', function ($query) use ($tags) {
                    $query->whereIn('product_tags.id', $tags);
                });
            })
            ->when($filters['brand'] ?? null, function ($query, int $brandId) {
                $query->where('brand_id', $brandId);
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString()
            ->through(function (Product $product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'is_active' => $product->is_active,
                    'variants' => $product->variants,
                    'prices' => $product->prices,
                    'brand' => $product->brand,
                    'categories' => $product->categories,
                    'tags' => $product->tags,
                ];
            });

        app(Seo::class)
            ->title('Shop')
            ->description('Browse our products.');

        return Inertia::render('commerce/Catalog', [
            'products' => $products,
            'filters' => [
                'search' => $filters['search'] ?? null,
                'category' => $filters['category'] ?? [],
                'tags' => $filters['tags'] ?? [],
                'brand' => $filters['brand'] ?? null,
            ],
            'categories' => ProductCategory::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
            'tags' => ProductTag::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(Product $product): Response
    {
        abort_unless($product->is_active, 404);

        $product->load(['options.values', 'variants.prices', 'prices', 'inventoryItems', 'categories', 'tags', 'brand']);

        app(Seo::class)
            ->title($product->name)
            ->description($product->description)
            ->canonical(route('shop.products.show', $product))
            ->schema(array_filter([
                '@type' => 'Product',
                'name' => $product->name,
                'description' => $product->description ? Str::limit(strip_tags($product->description), 500) : null,
                'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
                'url' => route('shop.products.show', $product),
            ]));

        return Inertia::render('commerce/ProductDetail', [
            'product' => $product,
        ]);
    }
}
