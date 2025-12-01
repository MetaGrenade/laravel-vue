<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\CartManager;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function show(Request $request): Response
    {
        $cart = CartManager::forRequest($request);

        return Inertia::render('commerce/Cart', [
            'cart' => $cart,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $product = Product::query()->with(['prices'])->findOrFail($validated['product_id']);

        $variant = null;

        if ($validated['product_variant_id'] ?? null) {
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->findOrFail($validated['product_variant_id']);
        }

        $price = $variant?->prices()->where('is_active', true)->orderBy('amount')->first()
            ?? $variant?->prices()->orderBy('amount')->first()
            ?? $product->prices()->where('is_active', true)->orderBy('amount')->first()
            ?? $product->prices()->orderBy('amount')->first();

        if (!$price instanceof Price) {
            return back()->with('error', 'This product is not available for purchase yet.');
        }

        $cart = CartManager::forRequest($request, true);

        CartManager::addItem(
            $cart,
            $product,
            $variant,
            $price,
            $validated['quantity'],
        );

        return back()->with('success', 'Added to your cart.');
    }
}
