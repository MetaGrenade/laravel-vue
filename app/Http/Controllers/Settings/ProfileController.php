<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Notifications\EmailAddressChanged;
use App\Support\Billing\SubscriptionCancellationFailed;
use App\Support\Billing\SubscriptionManager;
use App\Support\EmailVerification;
use App\Support\Localization\PreferenceOptions;
use App\Support\Security\EmailChange;
use App\Support\Security\SignInMethods;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => EmailVerification::isRequired(),
            'emailChangeAsksForPassword' => EmailChange::asksForPassword($request->user()),
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
        $previousEmail = (string) $user->email;

        // The password that was asked for to change the address has been checked by now and is not a field of the account.
        $user->fill($request->safe()->except('current_password'));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if (! $user->wasChanged('email')) {
            return to_route('profile.edit');
        }

        $this->tellThePreviousAddress($request, $user, $previousEmail);

        if ($user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();

            return to_route('profile.edit')->with('status', 'verification-link-sent');
        }

        return to_route('profile.edit');
    }

    /**
     * Say to the address the account had that it now has another, unless that address could not have received it (one the site made up
     * for a member whose provider gave none). A mail that cannot be queued is reported and does not undo a change that is made.
     */
    private function tellThePreviousAddress(Request $request, User $user, string $previousEmail): void
    {
        if ($previousEmail === '' || SignInMethods::isPlaceholderEmail($previousEmail)) {
            return;
        }

        try {
            $user->notify(new EmailAddressChanged($previousEmail, (string) $user->email, now(), $request->ip()));
        } catch (Throwable $exception) {
            report($exception);
        }
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

        // The person is signed out only once the subscriptions are ended, so that when Stripe cannot be reached the
        // account is left as it was, still signed in.
        try {
            $subscriptions->deleteUser($user, fn () => Auth::logout());
        } catch (SubscriptionCancellationFailed) {
            return back()->with('error', 'We could not cancel your subscription with our payment provider, so your account has not been deleted. Please try again in a few minutes.');
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // As when signing out: Back must not bring back what this browser showed the member (AuthenticatedSessionController::destroy).
        Inertia::clearHistory();

        return redirect('/');
    }
}
