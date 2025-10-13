<?php

use App\Http\Controllers\Settings\DataErasureRequestController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\LinkedSocialAccountController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SecuritySessionController;
use App\Http\Controllers\Settings\TwoFactorController;
use App\Http\Controllers\Settings\TwoFactorRecoveryCodeController;
use App\Http\Controllers\Settings\PrivacyController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\SubscriptionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])
        ->name('settings.notifications.edit');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])
        ->name('settings.notifications.update');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::get('settings/privacy', PrivacyController::class)->name('privacy.index');

    Route::get('settings/billing', [SubscriptionController::class, 'index'])->name('settings.billing.index');
    Route::post('settings/billing/setup-intent', [SubscriptionController::class, 'setupIntent'])->name('settings.billing.intent');
    Route::post('settings/billing/subscribe', [SubscriptionController::class, 'store'])->name('settings.billing.subscribe');
    Route::post('settings/billing/cancel', [SubscriptionController::class, 'cancel'])->name('settings.billing.cancel');
    Route::post('settings/billing/resume', [SubscriptionController::class, 'resume'])->name('settings.billing.resume');

    Route::post('settings/privacy/exports', [DataExportController::class, 'store'])
        ->name('privacy.exports.store');

    Route::get('settings/privacy/exports/{export}/download', [DataExportController::class, 'download'])
        ->middleware('signed')
        ->name('privacy.exports.download');

    Route::post('settings/privacy/erasure', [DataErasureRequestController::class, 'store'])
        ->name('privacy.erasure.store');

    Route::delete('settings/security/sessions/{session}', [SecuritySessionController::class, 'destroy'])
        ->name('security.sessions.destroy');

    Route::post('settings/security/mfa', [TwoFactorController::class, 'store'])->name('security.mfa.store');
    Route::post('settings/security/mfa/confirm', [TwoFactorController::class, 'confirm'])->name('security.mfa.confirm');
    Route::delete('settings/security/mfa', [TwoFactorController::class, 'destroy'])->name('security.mfa.destroy');

    Route::post('settings/security/recovery-codes', [TwoFactorRecoveryCodeController::class, 'store'])
        ->name('security.recovery-codes.store');

    Route::delete('settings/security/social/{provider}', [LinkedSocialAccountController::class, 'destroy'])
        ->name('settings.social.unlink');
});
