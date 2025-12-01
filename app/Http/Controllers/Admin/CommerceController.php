<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CommerceController extends Controller
{
    public function index(): Response
    {
        $products = Product::query()
            ->withCount(['variants', 'prices', 'inventoryItems'])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_active']);

        $variants = ProductVariant::query()
            ->with(['product:id,name'])
            ->withCount(['inventoryItems'])
            ->orderBy('name')
            ->get(['id', 'product_id', 'name', 'sku', 'is_default']);

        $prices = Price::query()
            ->latest()
            ->limit(20)
            ->get(['id', 'priceable_type', 'priceable_id', 'currency', 'amount', 'compare_at_amount', 'is_active']);

        $inventory = InventoryItem::query()
            ->with(['product:id,name', 'variant:id,name'])
            ->latest()
            ->limit(20)
            ->get(['id', 'product_id', 'product_variant_id', 'quantity', 'allow_backorder']);

        $orders = Order::query()
            ->with(['user:id,nickname,email'])
            ->latest()
            ->limit(10)
            ->get(['id', 'user_id', 'status', 'currency', 'grand_total', 'created_at']);

        $metrics = [
            'products' => [
                'total' => Product::count(),
                'active' => Product::where('is_active', true)->count(),
                'options' => ProductOption::count(),
                'variants' => ProductVariant::count(),
            ],
            'pricing' => [
                'active_prices' => Price::where('is_active', true)->count(),
                'total_prices' => Price::count(),
            ],
            'inventory' => [
                'items' => InventoryItem::count(),
                'on_hand' => (int) InventoryItem::sum('quantity'),
                'backorderable' => InventoryItem::where('allow_backorder', true)->count(),
            ],
            'orders' => [
                'total' => Order::count(),
                'processing' => Order::where('status', 'processing')->count(),
                'completed' => Order::where('status', 'completed')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
                'revenue' => (float) Order::sum('grand_total'),
            ],
        ];

        $orderStatusBreakdown = Order::query()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('acp/Commerce', [
            'products' => $products,
            'variants' => $variants,
            'prices' => $prices,
            'inventory' => $inventory,
            'orders' => $orders,
            'metrics' => $metrics,
            'orderStatusBreakdown' => $orderStatusBreakdown,
        ]);
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('products', 'slug')],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        Product::create($validated);

        return back()->with('success', 'Product created successfully.');
    }

    public function storeOption(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        ProductOption::create($validated);

        return back()->with('success', 'Option created successfully.');
    }

    public function storeOptionValue(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_option_id' => ['required', 'integer', Rule::exists('product_options', 'id')],
            'value' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        ProductOptionValue::create($validated);

        return back()->with('success', 'Option value created successfully.');
    }

    public function storeVariant(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')],
            'option_values' => ['nullable', 'array'],
            'option_values.*' => ['string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        ProductVariant::create($validated);

        return back()->with('success', 'Variant created successfully.');
    }

    public function storePrice(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'priceable_type' => ['required', 'string', Rule::in([Product::class, ProductVariant::class])],
            'priceable_id' => ['required', 'integer'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'min:0'],
            'compare_at_amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $exists = $validated['priceable_type'] === Product::class
            ? Product::whereKey($validated['priceable_id'])->exists()
            : ProductVariant::whereKey($validated['priceable_id'])->exists();

        if (! $exists) {
            return back()->withErrors(['priceable_id' => 'Selected item does not exist.']);
        }

        Price::create($validated);

        return back()->with('success', 'Price created successfully.');
    }

    public function storeInventory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'quantity' => ['required', 'integer', 'min:0'],
            'allow_backorder' => ['sometimes', 'boolean'],
        ]);

        InventoryItem::create($validated);

        return back()->with('success', 'Inventory item created successfully.');
    }
}
