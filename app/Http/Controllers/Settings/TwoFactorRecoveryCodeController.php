<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

class TwoFactorRecoveryCodeController extends Controller
{
    /**
     * Generate a fresh set of recovery codes.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            throw ValidationException::withMessages([
                'recovery' => 'Enable multi-factor authentication before generating recovery codes.',
            ]);
        }

        try {
            $recoveryCodes = TwoFactorAuthenticator::generateRecoveryCodes();
            $encryptedCodes = TwoFactorAuthenticator::encryptRecoveryCodes($recoveryCodes);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'recovery' => 'Recovery codes could not be generated. Please try again.',
            ]);
        }

        $user->forceFill([
            'two_factor_recovery_codes' => $encryptedCodes,
        ])->save();

        return back()->with('status', 'recovery-codes-generated');
    }
}
