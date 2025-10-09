<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\EmailVerification;
use App\Support\Localization\PreferenceOptions;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
        $validated = $request->validated();

        $uploadedAvatar = $request->file('avatar');
        $shouldRemoveAvatar = $request->boolean('remove_avatar');

        unset($validated['avatar'], $validated['remove_avatar']);

        $previousAvatarPath = $user->avatarStoragePath();

        if ($uploadedAvatar) {
            $newPath = $uploadedAvatar->store('avatars', 'public');
            $validated['avatar_url'] = $newPath;
            $shouldRemoveAvatar = false;
        } elseif ($shouldRemoveAvatar) {
            $validated['avatar_url'] = null;
        }

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $currentAvatarPath = $user->avatarStoragePath();

        if ($uploadedAvatar && $previousAvatarPath && $previousAvatarPath !== $currentAvatarPath) {
            Storage::disk('public')->delete($previousAvatarPath);
        } elseif ($shouldRemoveAvatar && $previousAvatarPath) {
            Storage::disk('public')->delete($previousAvatarPath);
        }

        if ($user instanceof MustVerifyEmail && $user->wasChanged('email')) {
            $user->sendEmailVerificationNotification();

            return to_route('profile.edit')->with('status', 'verification-link-sent');
        }

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
