<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesUpdateRequest;
use App\Support\NotificationChannelPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $channelPreferences = [];

        foreach (NotificationChannelPreferences::keys() as $key) {
            $channelPreferences[$key] = NotificationChannelPreferences::toggles($user, $key);
        }

        return Inertia::render('settings/Notifications', [
            'channelPreferences' => $channelPreferences,
            'emailIsVerified' => $user->hasVerifiedEmail(),
        ]);
    }

    public function update(NotificationPreferencesUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $validated = $request->validated();

        $preferences = $user->notification_preferences ?? [];

        foreach (NotificationChannelPreferences::keys() as $key) {
            if (isset($validated['channels'][$key])) {
                $preferences[$key] = $validated['channels'][$key];
            }
        }

        $user->notification_preferences = $preferences;
        $user->save();

        return to_route('notifications.edit')->with('success', 'Notification preferences updated.');
    }
}
