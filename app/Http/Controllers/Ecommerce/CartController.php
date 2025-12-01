<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function show(Request $request): Response
    {
        $cart = Cart::query()
            ->with(['items.product', 'items.variant'])
            ->where('user_id', $request->user()?->id)
            ->orWhere('session_id', $request->session()->getId())
            ->latest()
            ->first();

        return Inertia::render('commerce/Cart', [
            'cart' => $cart,
        ]);
    }
}
