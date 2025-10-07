<?php

namespace App\Support;

use App\Models\User;

class NotificationChannelPreferences
{
    /**
     * @var array<string, array<string, bool>>
     */
    private const DEFAULTS = [
        'support_ticket' => [
            'mail' => true,
            'push' => false,
            'database' => true,
        ],
        'forum_subscription' => [
            'mail' => true,
            'push' => false,
            'database' => true,
        ],
        'blog_subscription' => [
            'mail' => true,
            'push' => false,
            'database' => true,
        ],
    ];

    /**
     * @var list<string>
     */
    private const REGISTERED_KEYS = [
        'support_ticket',
        'forum_subscription',
        'blog_subscription',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return self::REGISTERED_KEYS;
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(string $key): array
    {
        return self::DEFAULTS[$key] ?? [
            'mail' => true,
            'push' => false,
            'database' => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function toggles(User $user, string $key): array
    {
        $stored = $user->notification_preferences;
        $defaults = self::defaults($key);

        if (!is_array($stored)) {
            return $defaults;
        }

        $overrides = $stored[$key] ?? [];

        if (!is_array($overrides)) {
            return $defaults;
        }

        $resolved = $defaults;

        foreach ($defaults as $channel => $enabled) {
            if (array_key_exists($channel, $overrides)) {
                $resolved[$channel] = (bool) $overrides[$channel];
            }
        }

        return $resolved;
    }

    /**
     * Resolve the enabled notification channels for a user and preference key.
     *
     * @return array<int, string>
     */
    public static function resolveChannels(User $user, string $key): array
    {
        $toggles = self::toggles($user, $key);

        $channels = [];

        if (($toggles['mail'] ?? false) && $user->hasVerifiedEmail()) {
            $channels[] = 'mail';
        }

        if ($toggles['push'] ?? false) {
            $channels[] = 'broadcast';
        }

        if ($toggles['database'] ?? false) {
            $channels[] = 'database';
        }

        return array_values(array_unique($channels));
    }
}
