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
                'recovery' => __('forms.two_factor.enable_first'),
            ]);
        }

        try {
            $recoveryCodes = TwoFactorAuthenticator::generateRecoveryCodes();
            $encryptedCodes = TwoFactorAuthenticator::encryptRecoveryCodes($recoveryCodes);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'recovery' => __('forms.two_factor.codes_failed'),
            ]);
        }

        $user->forceFill([
            'two_factor_recovery_codes' => $encryptedCodes,
        ])->save();

        return back()->with('status', 'recovery-codes-generated')->with(TwoFactorAuthenticator::SHOW_RECOVERY_CODES, true);
    }

    /**
     * Show the codes that are stored, once. The route asks for the password first (a stolen session must not be enough to read them), then
     * this sends the member back to the Security page with a flash that lets that one request carry the codes.
     */
    public function show(Request $request): RedirectResponse
    {
        $user = $request->user();

        $redirect = redirect()->route('security.edit');

        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            return $redirect;
        }

        return $redirect->with(TwoFactorAuthenticator::SHOW_RECOVERY_CODES, true);
    }
}
