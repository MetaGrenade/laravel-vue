<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductOption;
use App\Models\ProductReview;
use App\Models\ProductTag;
use App\Models\ProductVariant;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Payments\Tebex\TebexSelling;
use App\Support\Commerce\ProductAvailability;
use App\Support\Commerce\Reviews\ProductReviews;
use App\Support\Commerce\StorefrontProducts;
use App\Support\Commerce\Wishlists;
use App\Support\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop window. What it sends is only what a shopper should see: prices that can be charged,
 * variants that are on sale, pictures, and how much is left in words rather than numbers.
 */
class ProductCatalogController extends Controller
{
    public function index(Request $request, StorefrontProducts $storefront, ProductReviews $reviews, Wishlists $wishlists, PaymentManager $payments): Response|RedirectResponse
    {
        // A store that sells Tebex's packages has packages, not products. (One that sells its own products through Tebex's
        // Checkout API keeps this shop window.)
        if (TebexSelling::packages($payments->activeKey())) {
            return redirect()->route('shop.packages');
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'array'],
            'category.*' => ['integer'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
        ]);

        $products = StorefrontProducts::query()
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
            ->withQueryString();

        // What the reviews say and what this customer saved, for the whole page in one query each.
        $ids = $products->getCollection()->pluck('id');
        $summaries = $reviews->summaries($ids);
        $saved = $wishlists->among($request->user(), $ids);

        $products->through(fn (Product $product) => [
            ...$storefront->card($product, $reviews->rating($summaries, $product->id)),
            'wishlisted' => in_array($product->id, $saved, true),
        ]);

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

    public function show(Request $request, Product $product, ProductAvailability $availability, StorefrontProducts $storefront, ProductReviews $reviews, Wishlists $wishlists): Response
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
        $user = $request->user();
        $summary = $reviews->enabled() ? $reviews->summary($product) : null;

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
                // Only what published reviews say, and only when there are some.
                'aggregateRating' => $summary !== null && $summary['count'] > 0 ? [
                    '@type' => 'AggregateRating',
                    'ratingValue' => $summary['average'],
                    'reviewCount' => $summary['count'],
                    'bestRating' => 5,
                    'worstRating' => 1,
                ] : null,
            ]));

        return Inertia::render('commerce/ProductDetail', [
            'maxQuantity' => (int) config('commerce.checkout.max_quantity', 20),
            'wishlisted' => $wishlists->among($user, [$product->id]) !== [],
            'reviews' => $summary === null ? null : $this->reviewsPayload($request, $user, $product, $reviews, $summary),
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
                'variants' => $product->variants->map(fn (ProductVariant $variant) => $storefront->variant($variant, $product))->values(),
                'prices' => $product->prices->map(fn (Price $price) => $storefront->price($price))->values(),
            ],
        ]);
    }

    /**
     * What the product page shows of its reviews: the rating, one page of published reviews, the visitor's
     * own review (whatever state it is in) and whether they may write one.
     *
     * @param  array{count: int, average: float|null, distribution: array<int, int>}  $summary
     * @return array<string, mixed>
     */
    private function reviewsPayload(Request $request, ?User $user, Product $product, ProductReviews $reviews, array $summary): array
    {
        $requested = (string) $request->query('review_sort', 'newest');
        $sort = in_array($requested, ProductReviews::SORTS, true) ? $requested : 'newest';

        $page = $reviews->published($product, $sort)
            ->with('user:id,nickname')
            ->paginate((int) config('commerce.reviews.per_page', 10), ['*'], 'reviews_page');

        $mine = $user === null
            ? null
            : ProductReview::query()->where('product_id', $product->id)->where('user_id', $user->id)->first();

        $reason = $reviews->whyNot($user, $product);

        return [
            'summary' => $summary,
            'sort' => $sort,
            'items' => $page->getCollection()->map(fn (ProductReview $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'author' => $review->user->nickname,
                'verified_purchase' => $review->verified_purchase,
                'created_at' => $review->created_at?->toIso8601String(),
                'mine' => $user !== null && $review->user_id === $user->id,
            ])->values()->all(),
            'page' => ['current' => $page->currentPage(), 'last' => max(1, $page->lastPage()), 'total' => $page->total()],
            'mine' => $mine === null ? null : [
                'rating' => $mine->rating,
                'title' => $mine->title,
                'body' => $mine->body,
                'status' => $mine->status->value,
                'status_label' => $mine->status->label(),
                'verified_purchase' => $mine->verified_purchase,
                'created_at' => $mine->created_at?->toIso8601String(),
            ],
            // Writing (or changing) one is possible, and if not, why.
            'can_write' => $reason === null && ($mine === null || Gate::forUser($user)->allows('update', $mine)),
            'reason' => $reason,
            'message' => $reason === null ? null : $reviews->explain($reason),
            'limits' => [
                'title_max' => (int) config('commerce.reviews.title_max', 120),
                'body_min' => (int) config('commerce.reviews.body_min', 10),
                'body_max' => (int) config('commerce.reviews.body_max', 5000),
            ],
        ];
    }
}
