<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use function activity;
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
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = $request->user();

        if ($user && $user->two_factor_confirmed_at) {
            $request->session()->put('two_factor:id', $user->getKey());
            $request->session()->put('two_factor:remember', $request->boolean('remember'));

            Auth::logout();

            return redirect()->route('two-factor.login');
        }

        $request->session()->regenerate();

        if ($user) {
            activity('auth')
                ->event('auth.login')
                ->performedOn($user)
                ->causedBy($user)
                ->withProperties([
                    'attributes' => [
                        'remember' => $request->boolean('remember'),
                    ],
                ])
                ->log(sprintf('User %s logged in', $user->email ?? $user->id));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            activity('auth')
                ->event('auth.logout')
                ->performedOn($user)
                ->causedBy($user)
                ->log(sprintf('User %s logged out', $user->email ?? $user->id));
        }

        return redirect('/');
    }
}
