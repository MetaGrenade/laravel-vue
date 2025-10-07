<?php

namespace App\Support\Localization;

use DateTimeZone;
use Locale;
use ResourceBundle;

class PreferenceOptions
{
    protected static ?array $timezoneOptions = null;

    protected static ?array $localeOptions = null;

    /**
     * Retrieve the canonical timezone options for selection inputs.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function timezoneOptions(): array
    {
        if (self::$timezoneOptions === null) {
            self::$timezoneOptions = collect(DateTimeZone::listIdentifiers())
                ->map(fn (string $identifier) => [
                    'value' => $identifier,
                    'label' => str_replace('_', ' ', $identifier),
                ])
                ->values()
                ->all();
        }

        return self::$timezoneOptions;
    }

    /**
     * Return the list of allowable timezone values.
     *
     * @return array<int, string>
     */
    public static function timezoneValues(): array
    {
        return array_column(self::timezoneOptions(), 'value');
    }

    /**
     * Retrieve the canonical locale options for selection inputs.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function localeOptions(): array
    {
        if (self::$localeOptions === null) {
            $locales = ResourceBundle::getLocales('');

            self::$localeOptions = collect($locales)
                ->filter()
                ->map(fn (string $locale) => Locale::canonicalize($locale) ?: $locale)
                ->unique()
                ->sort()
                ->map(function (string $locale) {
                    $label = Locale::getDisplayName($locale, $locale);

                    return [
                        'value' => $locale,
                        'label' => $label ? ucfirst($label) : strtoupper($locale),
                    ];
                })
                ->values()
                ->all();
        }

        return self::$localeOptions;
    }

    /**
     * Return the list of allowable locale values.
     *
     * @return array<int, string>
     */
    public static function localeValues(): array
    {
        return array_column(self::localeOptions(), 'value');
    }

    public static function defaultTimezone(): string
    {
        return config('app.timezone');
    }

    public static function defaultLocale(): string
    {
        return config('app.locale');
    }
}
