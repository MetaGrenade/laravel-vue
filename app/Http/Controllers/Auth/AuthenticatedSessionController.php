<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Commerce\GuestCarryOver;
use App\Support\OAuth\OAuthProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            'socialProviders' => OAuthProviders::enabledOptions(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, GuestCarryOver $carryOver): RedirectResponse
    {
        // Signing in changes the browser's session, so what it did as a guest has to be noted first.
        $carryOver->remember($request);

        $request->authenticate();

        $user = $request->user();

        if ($user && $user->two_factor_confirmed_at) {
            $request->session()->put('two_factor:id', $user->getKey());
            $request->session()->put('two_factor:remember', $request->boolean('remember'));

            Auth::logout();

            return redirect()->route('two-factor.login');
        }

        $carryOver->afterSignIn($user, $request);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
