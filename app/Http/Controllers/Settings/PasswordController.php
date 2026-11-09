<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Password', [
            'mustVerifyEmail' => EmailVerification::isRequired(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // A new password is for whoever is sitting here. Every other browser that is signed in to the account is signed out by the change
        // itself (each session is checked against the password hash by `AuthenticateSession`), so whoever was using the old password, or
        // had taken a session along with it, has to sign in again. This call is for THIS device: its "remember me" cookie holds the old
        // hash and would stop working, so it is given a new one (and the framework's event for other devices being signed out is sent).
        Auth::logoutOtherDevices($validated['password']);

        return back();
    }
}
