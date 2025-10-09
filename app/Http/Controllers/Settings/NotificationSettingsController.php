<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $user->loadMissing('notificationSettings');

        $categoryConfig = (array) config('notification-preferences.categories', []);
        $channelConfig = (array) config('notification-preferences.channels', []);

        $categories = collect($categoryConfig)
            ->map(function (array $config, string $key) use ($user, $channelConfig) {
                $channels = collect($config['channels'] ?? array_keys($channelConfig ?? []))
                    ->filter(fn (string $channel) => isset($channelConfig[$channel]))
                    ->map(function (string $channel) use ($user, $key, $channelConfig) {
                        $setting = $user->notificationSettings->firstWhere('category', $key);
                        $defaultEnabled = (bool) ($channelConfig[$channel]['default'] ?? true);

                        return [
                            'key' => $channel,
                            'label' => $channelConfig[$channel]['label'] ?? ucfirst($channel),
                            'description' => $channelConfig[$channel]['description'] ?? null,
                            'enabled' => $setting
                                ? $setting->isChannelEnabled($channel)
                                : $defaultEnabled,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'key' => $key,
                    'label' => $config['label'] ?? ucfirst($key),
                    'description' => $config['description'] ?? null,
                    'channels' => $channels,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('settings/Notifications', [
            'categories' => $categories,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $categoryConfig = (array) config('notification-preferences.categories', []);
        $channelConfig = (array) config('notification-preferences.channels', []);

        $payload = $request->validate([
            'preferences' => ['required', 'array'],
        ]);

        $preferences = $payload['preferences'];

        if (! is_array($preferences)) {
            throw ValidationException::withMessages([
                'preferences' => 'Invalid notification preferences payload.',
            ]);
        }

        $user->loadMissing('notificationSettings');

        foreach ($categoryConfig as $categoryKey => $config) {
            $channelKeys = collect($config['channels'] ?? array_keys($channelConfig ?? []))
                ->filter(fn (string $channel) => isset($channelConfig[$channel]))
                ->values();

            $channelValues = [];

            foreach ($channelKeys as $channelKey) {
                $value = $preferences[$categoryKey][$channelKey] ?? null;
                $channelValues[$channelKey] = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
            }

            $user->notificationSettings()->updateOrCreate(
                ['category' => $categoryKey],
                [
                    'channel_mail' => $channelValues['mail'] ?? false,
                    'channel_push' => $channelValues['push'] ?? false,
                    'channel_database' => $channelValues['database'] ?? false,
                ],
            );
        }

        return redirect()
            ->route('settings.notifications.edit')
            ->with('status', 'Notification preferences updated.');
    }
}
