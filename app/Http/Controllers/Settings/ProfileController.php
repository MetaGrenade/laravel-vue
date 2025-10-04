<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $avatarFile = $request->file('avatar');
        $oldAvatarPath = $this->resolveStoredAvatarPath($user->avatar_url);

        if ($avatarFile) {
            $storedPath = $avatarFile->store('avatars/'.$user->id, 'public');
            $validated['avatar_url'] = Storage::disk('public')->url($storedPath);
        }

        unset($validated['avatar']);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if (isset($storedPath) && $oldAvatarPath && $oldAvatarPath !== $storedPath) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        return to_route('profile.edit');
    }

    private function resolveStoredAvatarPath(?string $avatarUrl): ?string
    {
        if (! $avatarUrl) {
            return null;
        }

        $disk = Storage::disk('public');
        $publicUrl = rtrim($disk->url(''), '/');

        if ($publicUrl !== '' && str_starts_with($avatarUrl, $publicUrl)) {
            return ltrim(Str::after($avatarUrl, $publicUrl), '/');
        }

        if (str_starts_with($avatarUrl, '/storage/')) {
            return ltrim(Str::after($avatarUrl, '/storage/'), '/');
        }

        if (! str_contains($avatarUrl, '://')) {
            return ltrim(preg_replace('/^storage\//', '', $avatarUrl), '/');
        }

        return null;
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
