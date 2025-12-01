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
            ->with(['items.variant', 'items.product'])
            ->when($request->user(), function ($query, $user) {
                $query->where('user_id', $user->id);
            })
            ->latest()
            ->paginate(10);

        return Inertia::render('commerce/Orders', [
            'orders' => $orders,
        ]);
    }
}
