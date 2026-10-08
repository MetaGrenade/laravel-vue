<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\ProductRequest;
use App\Models\Brand;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductTag;
use App\Models\ProductVariant;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Catalogue\CatalogueRemover;
use App\Support\Commerce\Catalogue\VariantGenerator;
use App\Support\Commerce\Money;
use App\Support\Commerce\PriceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Products in the ACP: find them, create them, edit everything about them in one place
 * (details, price, options and variants, stock) and delete or archive them.
 * Variants, options, prices and stock have their own controllers; they are edited from this page.
 */
class ProductController extends Controller
{
    use InteractsWithInertiaPagination;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly CatalogueRemover $remover,
        private readonly VariantGenerator $generator,
        private readonly PriceResolver $prices,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ['active', 'archived'], true) ? (string) $request->query('status') : '';
        $brand = $request->integer('brand') ?: null;

        $paginator = Product::query()
            ->with(['brand:id,name', 'prices', 'variants.prices', 'inventoryItems'])
            ->withCount('variants')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->whereLike('name', "%{$search}%")
                        ->orWhereLike('slug', "%{$search}%")
                        ->orWhereHas('variants', fn ($variants) => $variants->whereLike('sku', "%{$search}%"));
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'archived', fn ($query) => $query->where('is_active', false))
            ->when($brand !== null, fn ($query) => $query->where('brand_id', $brand))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $currency = $this->prices->currency();

        $products = $paginator->getCollection()->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'is_active' => $product->is_active,
            'brand' => $product->brand?->name,
            'variants_count' => $product->variants_count,
            'price' => $this->priceRange($product, $currency),
            'stock' => $this->stockSummary($this->itemsForSale($product)),
            'sellable' => $this->isSellable($product, $currency),
        ])->values()->all();

        $user = $request->user();

        return Inertia::render('acp/CommerceProducts', [
            'products' => [
                'data' => $products,
                ...$this->inertiaPagination($paginator),
            ],
            'filters' => ['search' => $search, 'status' => $status, 'brand' => $brand],
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'currency' => $currency,
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('acp/CommerceProductCreate', [
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name']),
            'tags' => ProductTag::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $product = DB::transaction(function () use ($validated) {
            $product = Product::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? $this->uniqueSlug($validated['name']),
                'description' => $validated['description'] ?? null,
                'brand_id' => $validated['brand_id'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'requires_shipping' => (bool) ($validated['requires_shipping'] ?? true),
                'is_taxable' => (bool) ($validated['is_taxable'] ?? true),
            ]);

            $product->categories()->sync($validated['category_ids'] ?? []);
            $product->tags()->sync($validated['tag_ids'] ?? []);

            return $product;
        });

        return redirect()
            ->route('acp.commerce.products.edit', $product)
            ->with('success', 'Product created. Add a price so it can be bought.');
    }

    public function edit(Request $request, Product $product): Response
    {
        $currency = $this->prices->currency();
        $user = $request->user();

        $product->load([
            'categories:id',
            'tags:id',
            'prices',
            'options' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'options.values' => fn ($query) => $query->orderBy('position')->orderBy('id'),
        ]);

        $variants = $product->variants()->with('prices')->orderByDesc('is_default')->orderBy('id')->get();
        $items = InventoryItem::query()->where('product_id', $product->id)->get();

        // Variants that have been ordered can only be switched off, not deleted.
        $ordered = OrderItem::query()
            ->whereIn('product_variant_id', $variants->pluck('id'))
            ->distinct()
            ->pluck('product_variant_id')
            ->all();

        $movements = $this->movements($items);
        $usedByOrders = InventoryMovement::query()
            ->whereIn('inventory_item_id', $items->pluck('id'))
            ->whereNotNull('order_id')
            ->distinct()
            ->pluck('inventory_item_id')
            ->all();

        return Inertia::render('acp/CommerceProductEdit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'brand_id' => $product->brand_id,
                'category_ids' => $product->categories->pluck('id')->all(),
                'tag_ids' => $product->tags->pluck('id')->all(),
                'is_active' => $product->is_active,
                'requires_shipping' => $product->requires_shipping,
                'is_taxable' => $product->is_taxable,
                'shop_url' => $product->is_active ? route('shop.products.show', $product) : null,
            ],
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name']),
            'tags' => ProductTag::query()->orderBy('name')->get(['id', 'name']),
            'currency' => $currency,
            'prices' => $product->prices->map(fn (Price $price) => $this->priceRow($price, $currency))->values(),
            'stock' => $this->stockRow($items->first(fn (InventoryItem $item) => $item->product_variant_id === null), $movements, $usedByOrders),
            'options' => $product->options->map(fn (ProductOption $option) => [
                'id' => $option->id,
                'name' => $option->name,
                'display_name' => $option->display_name,
                'values' => $option->values->map(fn ($value) => ['id' => $value->id, 'value' => $value->value])->values(),
            ])->values(),
            'variants' => $variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'option_values' => (object) ((array) $variant->option_values),
                'is_default' => $variant->is_default,
                'is_active' => $variant->is_active,
                'ordered' => in_array($variant->id, $ordered, true),
                'prices' => $variant->prices->map(fn (Price $price) => $this->priceRow($price, $currency))->values(),
                'stock' => $this->stockRow($items->first(fn (InventoryItem $item) => $item->product_variant_id === $variant->id), $movements, $usedByOrders),
            ])->values(),
            'missing_variants' => count($this->generator->missing($product)),
            'variant_limit' => VariantGenerator::LIMIT,
            'readiness' => $this->readiness($product, $variants, $currency),
            'deletion_block' => $this->remover->productBlock($product),
            'low_stock_threshold' => $this->lowStockThreshold(),
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $product) {
            $product->update([
                'name' => $validated['name'],
                // Left empty, the address stays as it is: a product's link should not change by accident.
                'slug' => $validated['slug'] ?? $product->slug,
                'description' => $validated['description'] ?? null,
                'brand_id' => $validated['brand_id'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? $product->is_active),
                'requires_shipping' => (bool) ($validated['requires_shipping'] ?? $product->requires_shipping),
                'is_taxable' => (bool) ($validated['is_taxable'] ?? $product->is_taxable),
            ]);

            if (array_key_exists('category_ids', $validated)) {
                $product->categories()->sync($validated['category_ids']);
            }

            if (array_key_exists('tag_ids', $validated)) {
                $product->tags()->sync($validated['tag_ids']);
            }
        });

        return back()->with('success', 'Product saved.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        try {
            $this->remover->deleteProduct($product);
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('acp.commerce.products.index')->with('success', 'Product deleted.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $suffix = 1;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * @return array{id: int, amount: string, compare_at_amount: string|null, currency: string, is_active: bool, usable: bool}
     */
    private function priceRow(Price $price, string $currency): array
    {
        return [
            'id' => $price->id,
            'amount' => $price->amount,
            'compare_at_amount' => $price->compare_at_amount,
            'currency' => $price->currency,
            'is_active' => $price->is_active,
            // Only an active price in the shop's currency can be charged.
            'usable' => $price->is_active && strtoupper($price->currency) === $currency,
        ];
    }

    /**
     * What is held in the movement ledger for each tracked item, newest first, capped.
     *
     * @param  Collection<int, InventoryItem>  $items
     * @return array<int, list<array<string, mixed>>>
     */
    private function movements(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            $rows[$item->id] = InventoryMovement::query()
                ->with(['user:id,nickname', 'order:id,number,public_id'])
                ->where('inventory_item_id', $item->id)
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (InventoryMovement $movement) => [
                    'id' => $movement->id,
                    'delta' => $movement->delta,
                    'reason' => $movement->reason,
                    'note' => $movement->note,
                    'by' => $movement->user?->nickname,
                    'order' => $movement->order ? ['number' => $movement->order->number, 'public_id' => $movement->order->public_id] : null,
                    'created_at' => $movement->created_at?->toIso8601String(),
                ])
                ->all();
        }

        return $rows;
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $movements
     * @param  list<int>  $usedByOrders  Ids of the stock rows that orders have used.
     * @return array<string, mixed>|null
     */
    private function stockRow(?InventoryItem $item, array $movements, array $usedByOrders): ?array
    {
        if ($item === null) {
            return null;
        }

        return [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'allow_backorder' => $item->allow_backorder,
            // Once orders have used it, their reservations live in its history, which is kept.
            'can_untrack' => ! in_array($item->id, $usedByOrders, true),
            'movements' => $movements[$item->id] ?? [],
        ];
    }

    /**
     * A product's stock rows, less those of variants that are switched off: stock that cannot be
     * sold is not what the product has available.
     *
     * @return Collection<int, InventoryItem>
     */
    private function itemsForSale(Product $product): Collection
    {
        $off = $product->variants->where('is_active', false)->pluck('id');

        return $product->inventoryItems->reject(fn (InventoryItem $item) => $item->product_variant_id !== null && $off->contains($item->product_variant_id))->values();
    }

    /**
     * The lowest and highest price a shopper could pay, in the shop's currency.
     *
     * @return array{from: string|null, to: string|null}
     */
    private function priceRange(Product $product, string $currency): array
    {
        $amounts = $this->usablePrices($product, $currency)
            ->map(fn (Price $price) => Money::parse($price->amount, $currency)->minor);

        if ($amounts->isEmpty()) {
            return ['from' => null, 'to' => null];
        }

        return [
            'from' => Money::ofMinor((int) $amounts->min(), $currency)->toDecimal(),
            'to' => Money::ofMinor((int) $amounts->max(), $currency)->toDecimal(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Price>
     */
    private function usablePrices(Product $product, string $currency): \Illuminate\Support\Collection
    {
        // A variant without a price of its own is sold at the product's price (see PriceResolver).
        $variantPrices = $product->variants->where('is_active', true)->flatMap(fn (ProductVariant $variant) => $variant->prices);

        return $product->prices->concat($variantPrices)
            ->filter(fn (Price $price) => $price->is_active && strtoupper($price->currency) === $currency);
    }

    /**
     * Whether a shopper could buy it: switched on, and priced in the shop's currency.
     */
    private function isSellable(Product $product, string $currency): bool
    {
        return $product->is_active && $this->usablePrices($product, $currency)->isNotEmpty();
    }

    /**
     * @param  Collection<int, InventoryItem>  $items
     * @return array{tracked: bool, total: int, status: 'untracked'|'in_stock'|'low'|'out'}
     */
    private function stockSummary(Collection $items): array
    {
        if ($items->isEmpty()) {
            return ['tracked' => false, 'total' => 0, 'status' => 'untracked'];
        }

        $threshold = $this->lowStockThreshold();
        $sellable = $items->filter(fn (InventoryItem $item) => $item->allow_backorder || $item->quantity > 0);

        $status = match (true) {
            $sellable->isEmpty() => 'out',
            $items->contains(fn (InventoryItem $item) => ! $item->allow_backorder && $item->quantity <= $threshold) => 'low',
            default => 'in_stock',
        };

        return [
            'tracked' => true,
            'total' => (int) $items->sum(fn (InventoryItem $item) => max(0, $item->quantity)),
            'status' => $status,
        ];
    }

    private function lowStockThreshold(): int
    {
        return max(0, (int) config('commerce.low_stock_threshold', 5));
    }

    /**
     * What is still needed before the product can be bought, for the checklist on its page.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<array{ok: bool, text: string}>
     */
    private function readiness(Product $product, Collection $variants, string $currency): array
    {
        $product->setRelation('variants', $variants);

        $checks = [
            ['ok' => $product->is_active, 'text' => $product->is_active ? 'Switched on in the shop' : 'Archived: not shown in the shop'],
        ];

        $hasPrice = $this->usablePrices($product, $currency)->isNotEmpty();
        $checks[] = ['ok' => $hasPrice, 'text' => $hasPrice ? "Has an active price in {$currency}" : "No active price in {$currency}: it cannot be bought"];

        if ($variants->isNotEmpty() && $product->prices->where('is_active', true)->isEmpty()) {
            $unpriced = $variants->where('is_active', true)->filter(fn (ProductVariant $variant) => $variant->prices->where('is_active', true)->isEmpty())->count();

            if ($unpriced > 0) {
                $checks[] = ['ok' => false, 'text' => $unpriced.' '.($unpriced === 1 ? 'variant has' : 'variants have').' no price of their own and the product has none to fall back on'];
            }
        }

        return $checks;
    }
}
