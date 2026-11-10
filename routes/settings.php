<?php

use App\Http\Controllers\Settings\AccountStandingController;
use App\Http\Controllers\Settings\AddressController;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\DataErasureRequestController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\DiscordNotificationSettingsController;
use App\Http\Controllers\Settings\LinkedSocialAccountController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PrivacyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SecuritySessionController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\Settings\TwoFactorController;
use App\Http\Controllers\Settings\TwoFactorRecoveryCodeController;
use App\Http\Middleware\RequirePasswordWhenTwoFactorIsOn;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The settings pages are sent the words of their frame (lang/en/settings.php) and no other page is.
Route::middleware(['auth', 'translations:settings'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->middleware('throttle:current-password')->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->middleware('throttle:current-password')->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    // The current password is checked, so a stolen session must not be able to guess it ('current-password' counts each member's tries).
    Route::put('settings/password', [PasswordController::class, 'update'])->middleware('throttle:current-password')->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])
        ->name('settings.notifications.edit');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])
        ->name('settings.notifications.update');

    Route::get('settings/discord-notifications', [DiscordNotificationSettingsController::class, 'edit'])
        ->name('settings.discord-notifications.edit');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::get('settings/privacy', PrivacyController::class)->name('privacy.index');

    // What moderators have warned this member about, and any ban they have been under (docs/moderation.md).
    Route::get('settings/account-standing', AccountStandingController::class)->name('settings.standing');

    Route::get('settings/api-keys', [ApiKeyController::class, 'index'])->name('settings.api-keys.index');
    // Making a key needs a verified address where the site asks for one; taking one away never does.
    Route::post('settings/api-keys', [ApiKeyController::class, 'store'])
        ->middleware(['verified', 'throttle:api-keys'])
        ->name('settings.api-keys.store');
    // Rotating makes a new key, so it is held to the same rules as making one.
    Route::post('settings/api-keys/{key}/rotate', [ApiKeyController::class, 'rotate'])
        ->middleware(['verified', 'throttle:api-keys'])
        ->whereNumber('key')
        ->name('settings.api-keys.rotate');
    Route::patch('settings/api-keys/{key}/limits', [ApiKeyController::class, 'limits'])
        ->whereNumber('key')
        ->name('settings.api-keys.limits');
    Route::post('settings/api-keys/{key}/revoke', [ApiKeyController::class, 'revoke'])
        ->whereNumber('key')
        ->name('settings.api-keys.revoke');
    Route::delete('settings/api-keys/{key}', [ApiKeyController::class, 'destroy'])
        ->whereNumber('key')
        ->name('settings.api-keys.destroy');

    // The address book belongs to the shop, so it goes away with it.
    Route::middleware('section.enabled:commerce')->group(function () {
        Route::get('settings/addresses', [AddressController::class, 'index'])->name('settings.addresses.index');
        Route::post('settings/addresses', [AddressController::class, 'store'])->name('settings.addresses.store');
        Route::put('settings/addresses/{address}', [AddressController::class, 'update'])->name('settings.addresses.update');
        Route::put('settings/addresses/{address}/default', [AddressController::class, 'default'])->name('settings.addresses.default');
        Route::delete('settings/addresses/{address}', [AddressController::class, 'destroy'])->name('settings.addresses.destroy');
    });

    Route::get('settings/billing', [SubscriptionController::class, 'index'])->name('settings.billing.index');
    Route::post('settings/billing/setup-intent', [SubscriptionController::class, 'setupIntent'])->name('settings.billing.intent');
    Route::post('settings/billing/promotion-code', [SubscriptionController::class, 'promotionCode'])
        ->middleware('throttle:promotion-codes')
        ->name('settings.billing.promotion-code');
    // Limited like the pricing page's. Starting a subscription looks a promotion code up too, so a request with one also counts
    // against the allowance for trying them.
    Route::post('settings/billing/subscribe', [SubscriptionController::class, 'store'])
        ->middleware(['throttle:billing', 'throttle:promotion-codes'])
        ->name('settings.billing.subscribe');
    Route::post('settings/billing/retry-payment', [SubscriptionController::class, 'retryPayment'])
        ->middleware('throttle:retry-payment')
        ->name('settings.billing.retry-payment');
    Route::post('settings/billing/portal', [SubscriptionController::class, 'portal'])
        ->middleware('throttle:billing')
        ->name('settings.billing.portal');
    Route::post('settings/billing/cancel', [SubscriptionController::class, 'cancel'])->name('settings.billing.cancel');
    Route::post('settings/billing/resume', [SubscriptionController::class, 'resume'])->name('settings.billing.resume');

    Route::get('settings/billing/invoices', [SubscriptionController::class, 'invoices'])->name('settings.billing.invoices.index');
    Route::get('settings/billing/invoices/{invoice}/download', [SubscriptionController::class, 'downloadInvoice'])
        ->name('settings.billing.invoices.download');

    Route::get('settings/billing/payment-methods', [SubscriptionController::class, 'paymentMethods'])
        ->name('settings.billing.payment-methods.index');
    Route::post('settings/billing/payment-methods', [SubscriptionController::class, 'storePaymentMethod'])
        ->name('settings.billing.payment-methods.store');
    Route::put('settings/billing/payment-methods/{paymentMethod}/default', [SubscriptionController::class, 'setDefaultPaymentMethod'])
        ->name('settings.billing.payment-methods.default');
    Route::delete('settings/billing/payment-methods/{paymentMethod}', [SubscriptionController::class, 'removePaymentMethod'])
        ->name('settings.billing.payment-methods.destroy');

    Route::post('settings/privacy/exports', [DataExportController::class, 'store'])
        ->name('privacy.exports.store');

    Route::get('settings/privacy/exports/{export}/download', [DataExportController::class, 'download'])
        ->middleware('signed')
        ->name('privacy.exports.download');

    Route::post('settings/privacy/erasure', [DataErasureRequestController::class, 'store'])
        ->name('privacy.erasure.store');

    Route::delete('settings/security/sessions/{session}', [SecuritySessionController::class, 'destroy'])
        ->name('security.sessions.destroy');

    // Every browser but this one, with any session driver (it changes the password hash the sessions are checked against). It asks for
    // the password, the same lever as changing it, and counts the tries like the password page does.
    Route::post('settings/security/sessions/others', [SecuritySessionController::class, 'destroyOthers'])
        ->middleware('throttle:current-password')
        ->name('security.sessions.destroy-others');

    // Switching two-factor off and making a new set of recovery codes ask for the password again (`password.confirm`, good for
    // AUTH_PASSWORD_TIMEOUT seconds): a browser that was left signed in must not be enough to take away the second factor, or to be
    // handed codes that replace it. Cancelling a setup that was never confirmed takes nothing away, so it does not ask. Ending another
    // session is left open on purpose, see docs/two-factor.md.
    Route::post('settings/security/mfa', [TwoFactorController::class, 'store'])->name('security.mfa.store');
    Route::post('settings/security/mfa/confirm', [TwoFactorController::class, 'confirm'])->name('security.mfa.confirm');
    Route::delete('settings/security/mfa', [TwoFactorController::class, 'destroy'])
        ->middleware(RequirePasswordWhenTwoFactorIsOn::class)
        ->name('security.mfa.destroy');

    Route::post('settings/security/recovery-codes', [TwoFactorRecoveryCodeController::class, 'store'])
        ->middleware('password.confirm')
        ->name('security.recovery-codes.store');

    // The Security page does not carry the recovery codes on every load. Asking to see them asks for the password (the same window as
    // above), then sends the member back to that page with the codes for that one request.
    Route::get('settings/security/recovery-codes', [TwoFactorRecoveryCodeController::class, 'show'])
        ->middleware('password.confirm')
        ->name('security.recovery-codes.show');

    Route::delete('settings/security/social/{provider}', [LinkedSocialAccountController::class, 'destroy'])
        ->name('settings.social.unlink');
});
