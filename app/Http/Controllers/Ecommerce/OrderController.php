<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->with('items')
            // Whoever owns the order, not whoever happened to place it.
            ->visibleTo($request->user())
            ->latest('id')
            ->paginate(10)
            ->through(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'payment_status' => $order->payment_status->value,
                'payment_status_label' => $order->payment_status->label(),
                'currency' => $order->currency,
                'grand_total' => $order->grand_total,
                'refunded_total' => $order->refunded_total,
                'created_at' => $order->created_at?->toIso8601String(),
                'url' => route('shop.checkout.complete', ['order' => $order->public_id]),
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'subtotal' => $item->subtotal,
                ])->values(),
            ]);

        return Inertia::render('commerce/Orders', [
            'orders' => $orders,
        ]);
    }
}
