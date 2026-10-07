<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Payments\PaymentManager;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\PriceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function show(Request $request, PaymentManager $payments): Response
    {
        $cart = CartManager::forRequest($request);

        return Inertia::render('commerce/Cart', [
            'cart' => CartManager::summary($cart),
            'checkoutAvailable' => $payments->active()->isConfigured(),
            'maxQuantity' => (int) config('commerce.checkout.max_quantity', 20),
        ]);
    }

    public function store(Request $request, PriceResolver $prices): RedirectResponse
    {
        $max = (int) config('commerce.checkout.max_quantity', 20);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', "max:{$max}"],
        ]);

        $product = Product::query()->where('is_active', true)->find($validated['product_id']);

        if ($product === null) {
            return back()->with('error', 'This product is not available.');
        }

        $variant = null;

        if ($validated['product_variant_id'] ?? null) {
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->findOrFail($validated['product_variant_id']);
        }

        $price = $prices->resolve($product, $variant);

        if ($price === null) {
            return back()->with('error', 'This product is not available for purchase yet.');
        }

        $cart = CartManager::forRequest($request, true);

        CartManager::addItem($cart, $product, $variant, $price, $validated['quantity']);

        return back()->with('success', 'Added to your cart.');
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $item);

        $max = (int) config('commerce.checkout.max_quantity', 20);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', "max:{$max}"],
        ]);

        CartManager::updateQuantity($item, $validated['quantity']);

        return back()->with('success', 'Cart updated.');
    }

    public function destroy(Request $request, CartItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $item);

        CartManager::removeItem($item);

        return back()->with('success', 'Removed from your cart.');
    }

    /**
     * Cart lines are only ever changed through the shopper's own open cart.
     */
    private function authorizeItem(Request $request, CartItem $item): void
    {
        $cart = CartManager::forRequest($request);

        abort_unless($cart !== null && $item->cart_id === $cart->id, 404);
    }
}
