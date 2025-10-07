<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        $rawPreferences = $user?->notification_preferences;

        $preferences = [];

        if (is_array($rawPreferences) && is_array($rawPreferences['support_ticket'] ?? null)) {
            $preferences = $rawPreferences['support_ticket'];
        }

        return Inertia::render('settings/Notifications', [
            'channelPreferences' => [
                'mail' => (bool) ($preferences['mail'] ?? true),
                'push' => (bool) ($preferences['push'] ?? false),
                'database' => (bool) ($preferences['database'] ?? true),
            ],
            'emailIsVerified' => $user?->hasVerifiedEmail() ?? false,
        ]);
    }

    public function update(NotificationPreferencesUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $preferences = $user->notification_preferences ?? [];

        $preferences['support_ticket'] = $request->validated('channels');

        $user->notification_preferences = $preferences;
        $user->save();

        return to_route('notifications.edit')->with('success', 'Notification preferences updated.');
    }
}
