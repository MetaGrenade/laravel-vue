<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductOption;
use App\Models\ProductTag;
use App\Models\ProductVariant;
use App\Support\Commerce\ProductAvailability;
use App\Support\Seo\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop window. What it sends is only what a shopper should see: prices that can be charged,
 * variants that are on sale, pictures, and how much is left in words rather than numbers.
 */
class ProductCatalogController extends Controller
{
    public function index(Request $request, ProductAvailability $availability): Response
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
            ->with([
                'variants' => ProductAvailability::activeVariants(),
                'prices' => ProductAvailability::chargeablePrices(),
                'inventoryItems:id,product_id,product_variant_id,quantity,allow_backorder',
                'primaryImage',
                'categories:id,name,slug',
                'tags:id,name,slug',
                'brand:id,name,slug',
            ])
            ->withExists('variants as has_variants')
            ->where('is_active', true)
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereLike('name', "%{$search}%")
                        ->orWhereLike('description', "%{$search}%");
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
            ->through(function (Product $product) use ($availability) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'is_active' => $product->is_active,
                    // What checkout will accept, so the button is never on for something it would refuse.
                    'can_buy' => $availability->canBuy($product),
                    'sold_out' => $availability->soldOut($product),
                    'image' => $product->primaryImage?->toStorefront($product->name),
                    'variants' => $product->variants->map(fn (ProductVariant $variant) => $this->variantPayload($variant, $product, $availability))->values(),
                    'prices' => $product->prices->map(fn (Price $price) => $this->pricePayload($price))->values(),
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

    public function show(Product $product, ProductAvailability $availability): Response
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'options' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'options.values' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'variants' => ProductAvailability::activeVariants(),
            'prices' => ProductAvailability::chargeablePrices(),
            'inventoryItems:id,product_id,product_variant_id,quantity,allow_backorder',
            'images',
            'categories',
            'tags',
            'brand',
        ]);
        $product->loadExists('variants as has_variants');
        $downloads = $product->files()->where('is_active', true)->count();

        $canBuy = $availability->canBuy($product);
        $soldOut = $availability->soldOut($product);
        $images = $product->images->map(fn (ProductImage $image) => $image->toStorefront($product->name))->values();
        $lowest = $availability->lowestPrice($product);

        app(Seo::class)
            ->title($product->name)
            ->description($product->description)
            ->canonical(route('shop.products.show', $product))
            ->image($product->images->first()?->url(), $product->images->first()?->alt ?: $product->name)
            ->schema(array_filter([
                '@type' => 'Product',
                'name' => $product->name,
                'description' => $product->description ? Str::limit(strip_tags($product->description), 500) : null,
                'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
                'image' => $product->images->map(fn (ProductImage $image) => $image->url())->all() ?: null,
                'url' => route('shop.products.show', $product),
                // Only when it can be bought, and priced as it will be charged.
                'offers' => $canBuy && $lowest !== null ? [
                    '@type' => 'Offer',
                    'price' => $lowest->amount,
                    'priceCurrency' => $lowest->currency,
                    'availability' => $soldOut ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
                    'url' => route('shop.products.show', $product),
                ] : null,
            ]));

        return Inertia::render('commerce/ProductDetail', [
            'maxQuantity' => (int) config('commerce.checkout.max_quantity', 20),
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'brand' => $product->brand ? ['id' => $product->brand->id, 'name' => $product->brand->name, 'slug' => $product->brand->slug] : null,
                'categories' => $product->categories->map(fn (ProductCategory $category) => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug])->values(),
                'tags' => $product->tags->map(fn (ProductTag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug])->values(),
                'can_buy' => $canBuy,
                'sold_out' => $soldOut,
                // How much is left, for a product sold as it is. (Variants carry their own.)
                'stock' => $availability->status($product),
                'requires_shipping' => $product->requires_shipping,
                // How many files come with it once paid (the file names are for buyers only).
                'downloads' => $downloads,
                'images' => $images,
                'options' => $product->options
                    ->filter(fn (ProductOption $option) => $option->values->isNotEmpty())
                    ->map(fn (ProductOption $option) => [
                        'id' => $option->id,
                        'name' => $option->name,
                        'display_name' => $option->display_name,
                        'values' => $option->values->map(fn ($value) => $value->value)->values(),
                    ])->values(),
                'variants' => $product->variants->map(fn (ProductVariant $variant) => $this->variantPayload($variant, $product, $availability))->values(),
                'prices' => $product->prices->map(fn (Price $price) => $this->pricePayload($price))->values(),
            ],
        ]);
    }

    /**
     * @return array{id: int, name: string, sku: string|null, option_values: object, is_default: bool, stock: string, prices: list<array<string, mixed>>}
     */
    private function variantPayload(ProductVariant $variant, Product $product, ProductAvailability $availability): array
    {
        return [
            'id' => $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'option_values' => (object) ((array) $variant->option_values),
            'is_default' => $variant->is_default,
            'stock' => $availability->status($product, $variant),
            'prices' => $variant->prices->map(fn (Price $price) => $this->pricePayload($price))->values()->all(),
        ];
    }

    /**
     * @return array{id: int, currency: string, amount: string, compare_at_amount: string|null}
     */
    private function pricePayload(Price $price): array
    {
        return [
            'id' => $price->id,
            'currency' => $price->currency,
            'amount' => $price->amount,
            'compare_at_amount' => $price->compare_at_amount,
        ];
    }
}
