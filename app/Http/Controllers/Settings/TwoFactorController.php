<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use JsonException;

class TwoFactorController extends Controller
{
    /**
     * Generate and persist a new two-factor secret.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        // A new secret clears the confirmation, so setting up again on top of a confirmed one would switch two-factor off without asking
        // anybody for anything. Turning it off asks for the password first (see the route), and only then can a new one be set up.
        if ($user->two_factor_confirmed_at) {
            return back()->with('status', 'two-factor-already-on');
        }

        $secret = TwoFactorAuthenticator::generateSecret();

        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret($secret),
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return back()->with('status', 'two-factor-secret-generated');
    }

    /**
     * Confirm two-factor authentication with the provided code.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if (! $user->two_factor_secret) {
            throw ValidationException::withMessages([
                'code' => __('forms.two_factor.not_started'),
            ]);
        }

        $secret = TwoFactorAuthenticator::decryptSecret($user->two_factor_secret);

        if (! $secret || ! TwoFactorAuthenticator::verify($secret, $request->string('code'), 1, now()->timestamp)) {
            throw ValidationException::withMessages([
                'code' => __('forms.two_factor.code_invalid'),
            ]);
        }

        try {
            $recoveryCodes = TwoFactorAuthenticator::generateRecoveryCodes();
            $encryptedCodes = TwoFactorAuthenticator::encryptRecoveryCodes($recoveryCodes);

            $user->forceFill([
                'two_factor_confirmed_at' => Carbon::now(),
                'two_factor_recovery_codes' => $encryptedCodes,
            ])->save();
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'code' => __('forms.two_factor.codes_failed'),
            ]);
        }

        // The first set is shown now, once: a page that is loaded later does not carry it (see SecurityController::edit).
        return back()->with('status', 'two-factor-confirmed')->with(TwoFactorAuthenticator::SHOW_RECOVERY_CODES, true);
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return back()->with('status', 'two-factor-disabled');
    }
}
