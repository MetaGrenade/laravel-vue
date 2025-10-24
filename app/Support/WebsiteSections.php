<?php

namespace App\Support;

use App\Models\SystemSetting;

class WebsiteSections
{
    public const BLOG = 'blog';
    public const FORUM = 'forum';
    public const SUPPORT = 'support';

    /**
     * Retrieve the default section availability map.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            self::BLOG => true,
            self::FORUM => true,
            self::SUPPORT => true,
        ];
    }

    /**
     * Get all supported section keys.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::defaults());
    }

    /**
     * Determine if a section is enabled.
     */
    public static function isEnabled(string $section): bool
    {
        $sections = self::all();

        if (! array_key_exists($section, $sections)) {
            return false;
        }

        return (bool) $sections[$section];
    }

    /**
     * Retrieve the stored section configuration merged with defaults.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $stored = SystemSetting::get('website_sections');

        $defaults = self::defaults();

        if (! is_array($stored)) {
            return $defaults;
        }

        $normalized = [];

        foreach ($defaults as $section => $enabled) {
            $normalized[$section] = isset($stored[$section])
                ? (bool) $stored[$section]
                : (bool) $enabled;
        }

        return $normalized;
    }

    /**
     * Normalize an incoming payload of section toggles.
     *
     * @param  array<string, mixed>  $sections
     * @return array<string, bool>
     */
    public static function normalize(array $sections): array
    {
        $normalized = [];

        foreach (self::defaults() as $section => $enabled) {
            $normalized[$section] = (bool) ($sections[$section] ?? $enabled);
        }

        return $normalized;
    }
}
