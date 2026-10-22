<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\Billing\SubscriptionCancellationFailed;
use App\Support\Billing\SubscriptionManager;
use App\Support\EmailVerification;
use App\Support\Localization\PreferenceOptions;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => EmailVerification::isRequired(),
            'status' => $request->session()->get('status'),
            'timezoneOptions' => PreferenceOptions::timezoneOptions(),
            'localeOptions' => PreferenceOptions::localeOptions(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($user instanceof MustVerifyEmail && $user->wasChanged('email')) {
            $user->sendEmailVerificationNotification();

            return to_route('profile.edit')->with('status', 'verification-link-sent');
        }

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile, after ending any subscription they have so that it cannot go on charging them.
     */
    public function destroy(Request $request, SubscriptionManager $subscriptions): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Before signing out, so that when Stripe cannot be reached the account is left as it was, still signed in.
        try {
            $subscriptions->cancelAllNow($user);
        } catch (SubscriptionCancellationFailed) {
            return back()->with('error', 'We could not cancel your subscription with our payment provider, so your account has not been deleted. Please try again in a few minutes.');
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
