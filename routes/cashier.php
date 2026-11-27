<?php

use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\PaymentController;

// Of the routes Cashier would register by itself, the one the site keeps (AppServiceProvider::register() switches its own off): the
// page where a customer confirms a payment with their bank, which Cashier's ConfirmPayment email links to. It is in no middleware
// group, as in Cashier. Its page writes a script that the content security policy would refuse (docs/security-headers.md).
//
// Cashier's webhook is left out. Stripe's events go to `stripe/webhook` (web.php), which always checks the signature; Cashier's own
// checks it only when STRIPE_WEBHOOK_SECRET is set, and otherwise takes what anybody sends.
Route::group(['prefix' => config('cashier.path'), 'as' => 'cashier.'], function () {
    Route::get('payment/{id}', [PaymentController::class, 'show'])->name('payment');
});
