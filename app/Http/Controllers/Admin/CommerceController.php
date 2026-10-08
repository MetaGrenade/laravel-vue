<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The commerce overview in the ACP: how the shop is doing, and what needs attention.
 * Managing products is {@see Catalogue\ProductController}; orders are {@see CommerceOrderController}.
 */
class CommerceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $threshold = max(0, (int) config('commerce.low_stock_threshold', 5));

        $orders = Order::query()
            ->with(['user:id,nickname,email'])
            ->latest()
            ->limit(10)
            ->get(['id', 'public_id', 'number', 'user_id', 'status', 'payment_status', 'currency', 'grand_total', 'created_at']);

        // Tracked stock that is out or nearly out, worst first. Items that may be backordered are
        // sold regardless, so they are not a worry.
        $lowStock = InventoryItem::query()
            ->forSale()
            ->with(['product:id,name', 'variant:id,name'])
            ->where('allow_backorder', false)
            ->where('quantity', '<=', $threshold)
            ->orderBy('quantity')
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product?->name,
                'variant' => $item->variant?->name,
                'quantity' => $item->quantity,
            ])
            ->values();

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
                // Only stock that belongs to something on sale: an archived product or a variant that is
                // off keeps its row for the history, but it is not a shortage.
                'items' => InventoryItem::forSale()->count(),
                'on_hand' => (int) InventoryItem::forSale()->where('quantity', '>', 0)->sum('quantity'),
                'backorderable' => InventoryItem::forSale()->where('allow_backorder', true)->count(),
                'out_of_stock' => InventoryItem::forSale()->where('allow_backorder', false)->where('quantity', '<=', 0)->count(),
            ],
            'orders' => [
                'total' => Order::count(),
                'processing' => Order::where('status', 'processing')->count(),
                'completed' => Order::where('status', 'completed')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
                // What was actually taken: only orders that were paid, less what was refunded.
                'revenue' => (float) Order::query()
                    ->whereIn('payment_status', array_map(
                        fn (OrderPaymentStatus $status) => $status->value,
                        array_filter(OrderPaymentStatus::cases(), fn (OrderPaymentStatus $status) => $status->hasReceivedPayment()),
                    ))
                    ->sum(DB::raw('grand_total - refunded_total')),
            ],
        ];

        return Inertia::render('acp/Commerce', [
            'orders' => $orders,
            'metrics' => $metrics,
            'orderStatusBreakdown' => Order::query()
                ->select('status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status'),
            'lowStock' => $lowStock,
            'lowStockThreshold' => $threshold,
            'currency' => strtoupper((string) config('commerce.currency', 'USD')),
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
            ],
        ]);
    }
}
