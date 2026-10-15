<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Payments\PaymentManager;
use App\Payments\Providers\TebexProvider;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\Discounts\CartCoupons;
use App\Support\Commerce\GiftCards\CartGiftCards;
use App\Support\Commerce\PriceResolver;
use App\Support\Commerce\Pricing;
use App\Support\Commerce\ShippingCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function show(Request $request, PaymentManager $payments, CartCoupons $coupons, CartGiftCards $giftCards, ShippingCalculator $shipping): Response
    {
        $cart = CartManager::forRequest($request);
        $hasItems = $cart !== null && $cart->items->isNotEmpty();

        // Tebex packages are priced, discounted and taxed by Tebex: the shop's own codes, gift cards, shipping and tax
        // are not part of them.
        $tebex = CartManager::kind($cart) === CartManager::PACKAGES;

        // Priced once, with its code, the way checkout will price it.
        $pricing = $hasItems && ! $tebex ? $coupons->price($cart, $request->user()) : null;

        return Inertia::render('commerce/Cart', [
            'cart' => CartManager::summary($cart),
            'tebex' => $tebex,
            // The code on the cart, and what it takes off now (checked again at checkout).
            'coupon' => $hasItems && ! $tebex ? $coupons->describe($cart, $pricing) : null,
            // The gift card on the cart, and whether the shop takes them at all.
            'giftCard' => $hasItems && ! $tebex ? $giftCards->describe($cart) : null,
            'giftCardsEnabled' => $giftCards->enabled() && ! $tebex,
            // Something to pay needs a payment provider; a cart that costs nothing once its code is
            // applied does not. Where shipping is charged, that is not known before an address is given.
            'checkoutAvailable' => $payments->active()->isConfigured() || $this->costsNothing($pricing, $shipping),
            'maxQuantity' => (int) config('commerce.checkout.max_quantity', 20),
        ]);
    }

    /**
     * Whether the cart is free as it stands: nothing left to pay after any discount or gift card, and no shipping
     * still to be charged for an address that has not been given yet.
     */
    private function costsNothing(?Pricing $pricing, ShippingCalculator $shipping): bool
    {
        return $pricing !== null
            && $pricing->amountDue()->isZero()
            && (! $pricing->needsShipping || ! $shipping->isConfigured());
    }

    public function store(Request $request, PriceResolver $prices, PaymentManager $payments): RedirectResponse
    {
        if ($payments->activeKey() === TebexProvider::KEY) {
            return back()->with('error', 'This shop sells packages, not products.');
        }

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
            // A variant of some other product is a tampered request; one that was switched off is
            // just no longer for sale.
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->findOrFail($validated['product_variant_id']);

            if (! $variant->is_active) {
                return back()->with('error', 'This option is not available.');
            }
        } elseif ($product->variants()->exists()) {
            // A product with variants is only ever bought as one of them. Without this, switching
            // every variant off would let it be bought as the base product at the product's price,
            // skipping the variants' stock.
            return back()->with('error', $product->variants()->where('is_active', true)->exists()
                ? 'Choose an option for this product.'
                : 'This product is not available.');
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
