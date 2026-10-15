<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\TebexPackage;
use App\Payments\PaymentManager;
use App\Payments\Providers\TebexProvider;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Putting a Tebex package in the shopper's cart. Changing a quantity or removing a line works like any other line, through
 * {@see CartController}.
 */
class CartPackageController extends Controller
{
    public function store(Request $request, PaymentManager $payments): RedirectResponse
    {
        abort_unless($payments->activeKey() === TebexProvider::KEY, 404);

        $validated = $request->validate([
            'package_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.(int) config('commerce.checkout.max_quantity', 20)],
        ]);

        // By the id Tebex gave it, which is what the package's page is at.
        $package = TebexPackage::query()->onSale()->where('tebex_id', $validated['package_id'])->first();

        if ($package === null) {
            return back()->with('error', 'This package is not available.');
        }

        if ($reason = $package->whyNotForSale()) {
            return back()->with('error', ucfirst("{$package->name} {$reason}."));
        }

        try {
            CartManager::addPackage(CartManager::forRequest($request, true), $package, (int) $validated['quantity']);
        } catch (CheckoutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Added to your cart.');
    }
}
