<?php

namespace App\Support\Localization;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

/**
 * Flattens translation groups into dot-notation strings for the Vue front end.
 */
class FrontendTranslations
{
    /**
     * @param  list<string>  $groups
     * @return array<string, string> e.g. ['ui.nav.home' => 'Home']
     */
    public static function forGroups(array $groups, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $fallback = (string) config('app.fallback_locale', 'en');
        $strings = [];

        foreach ($groups as $group) {
            // Fallback language first so a partly translated locale still has every key.
            foreach (array_unique([$fallback, $locale]) as $code) {
                $lines = Lang::get($group, [], $code, false);

                if (! is_array($lines)) {
                    continue;
                }

                foreach (Arr::dot($lines) as $key => $value) {
                    if (is_string($value)) {
                        $strings["{$group}.{$key}"] = $value;
                    }
                }
            }
        }

        return $strings;
    }
}
