<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    /**
     * Display the multi-factor authentication challenge screen.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('two_factor:id')) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/TwoFactorChallenge');
    }

    /**
     * Handle an incoming multi-factor authentication challenge.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $userId = $request->session()->get('two_factor:id');

        if (! $userId) {
            $this->clearTwoFactorSession($request);

            return redirect()->route('login');
        }

        $user = User::find($userId);

        if (! $user || is_null($user->two_factor_confirmed_at)) {
            $this->clearTwoFactorSession($request);

            return redirect()->route('login');
        }

        $code = $request->string('code')->trim()->value();
        $recoveryCode = Str::of($request->string('recovery_code')->value())
            ->upper()
            ->replace(' ', '')
            ->value();

        if ($code === '' && $recoveryCode === '') {
            throw ValidationException::withMessages([
                'code' => trans('auth.two_factor_code_required'),
            ]);
        }

        if ($code !== '') {
            $secret = TwoFactorAuthenticator::decryptSecret($user->two_factor_secret);

            if (! $secret || ! TwoFactorAuthenticator::verify($secret, $code)) {
                throw ValidationException::withMessages([
                    'code' => trans('auth.two_factor_code_invalid'),
                ]);
            }
        } else {
            $recoveryCodes = TwoFactorAuthenticator::decryptRecoveryCodes($user->two_factor_recovery_codes);

            if (! in_array($recoveryCode, $recoveryCodes, true)) {
                throw ValidationException::withMessages([
                    'recovery_code' => trans('auth.two_factor_recovery_code_invalid'),
                ]);
            }

            $remainingCodes = array_values(array_diff($recoveryCodes, [$recoveryCode]));

            $user->forceFill([
                'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes($remainingCodes),
            ])->save();
        }

        $remember = (bool) $request->session()->pull('two_factor:remember', false);
        $request->session()->forget('two_factor:id');

        Auth::login($user, $remember);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function clearTwoFactorSession(Request $request): void
    {
        $request->session()->forget(['two_factor:id', 'two_factor:remember']);
    }
}
