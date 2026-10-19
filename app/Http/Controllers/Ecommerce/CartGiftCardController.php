<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\GiftCards\CartGiftCards;
use App\Support\Commerce\GiftCards\GiftCardRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Putting a gift card on the shopper's cart, and taking it off again. Works from the cart page and the
 * checkout page, and for guests. Trying codes is slowed right down by the route's limiter.
 */
class CartGiftCardController extends Controller
{
    public function store(Request $request, CartGiftCards $giftCards): RedirectResponse
    {
        $validated = $request->validate([
            'gift_card' => ['required', 'string', 'max:60'],
        ], [
            'gift_card.required' => 'Enter your gift card code.',
        ]);

        $cart = CartManager::forRequest($request);

        if ($cart === null || $cart->items->isEmpty()) {
            return back()->withErrors(['gift_card' => 'Add something to your cart before using a gift card.']);
        }

        if (CartManager::kind($cart) === CartManager::PACKAGES) {
            return back()->withErrors(['gift_card' => "Tebex takes gift cards when you pay, so the shop's own gift cards are not used with packages."]);
        }

        try {
            $card = $giftCards->apply($cart, $validated['gift_card']);
        } catch (GiftCardRejected $exception) {
            return back()->withErrors(['gift_card' => $exception->getMessage()]);
        }

        return back()->with('success', "Gift card ending {$card->last_four} applied.");
    }

    public function destroy(Request $request, CartGiftCards $giftCards): RedirectResponse
    {
        $cart = CartManager::forRequest($request);

        if ($cart !== null) {
            $giftCards->remove($cart);
        }

        return back()->with('success', 'Gift card removed.');
    }
}
