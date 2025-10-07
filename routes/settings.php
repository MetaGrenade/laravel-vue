<?php

use App\Http\Controllers\Settings\NotificationPreferenceController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SecuritySessionController;
use App\Http\Controllers\Settings\TwoFactorController;
use App\Http\Controllers\Settings\TwoFactorRecoveryCodeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])
        ->name('notifications.edit');
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('notifications.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::delete('settings/security/sessions/{session}', [SecuritySessionController::class, 'destroy'])
        ->name('security.sessions.destroy');

    Route::post('settings/security/mfa', [TwoFactorController::class, 'store'])->name('security.mfa.store');
    Route::post('settings/security/mfa/confirm', [TwoFactorController::class, 'confirm'])->name('security.mfa.confirm');
    Route::delete('settings/security/mfa', [TwoFactorController::class, 'destroy'])->name('security.mfa.destroy');

    Route::post('settings/security/recovery-codes', [TwoFactorRecoveryCodeController::class, 'store'])
        ->name('security.recovery-codes.store');
});
