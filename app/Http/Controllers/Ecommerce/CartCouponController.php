<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutException;
use App\Support\Commerce\Discounts\CartCoupons;
use App\Support\Commerce\Discounts\CouponRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Putting a discount code on the shopper's cart, and taking it off again. Works from the cart page
 * and the checkout page, and for guests.
 */
class CartCouponController extends Controller
{
    public function store(Request $request, CartCoupons $coupons): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60'],
        ], [
            'code.required' => 'Enter a discount code.',
        ]);

        $cart = CartManager::forRequest($request);

        if ($cart === null || $cart->items->isEmpty()) {
            return back()->withErrors(['code' => 'Add something to your cart before using a code.']);
        }

        try {
            $coupon = $coupons->apply($cart, $validated['code'], $request->user());
        } catch (CouponRejected|CheckoutException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        return back()->with('success', "Code {$coupon->code} applied.");
    }

    public function destroy(Request $request, CartCoupons $coupons): RedirectResponse
    {
        $cart = CartManager::forRequest($request);

        if ($cart !== null) {
            $coupons->remove($cart);
        }

        return back()->with('success', 'Code removed.');
    }
}
