<?php

namespace App\Support\Localization;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Which interface languages the site has, and which of them a stored value ("fr", "fr_CA", "EN-gb") names (docs/i18n.md). One place
 * for the rules, because a request (`SetLocale`) and an email (`User::preferredLocale()`) have to choose in the same way.
 */
final class Locales
{
    /**
     * The languages the interface is translated into: `config('i18n.supported')` reduced to language codes, never empty.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        $supported = [];

        foreach ((array) config('i18n.supported', ['en']) as $value) {
            $language = self::language($value);

            if ($language !== null) {
                $supported[] = $language;
            }
        }

        return $supported !== [] ? array_values(array_unique($supported)) : ['en'];
    }

    /**
     * The language used when nothing asks for another one: `APP_LOCALE`, or the first supported language if that is not one.
     */
    public static function default(): string
    {
        $supported = self::supported();
        $default = self::language((string) config('app.locale', 'en'));

        return $default !== null && in_array($default, $supported, true) ? $default : $supported[0];
    }

    /**
     * The language a stored value names, if the site has it; null when it is empty, not a locale, or a language that is not supported.
     */
    public static function supportedLanguage(mixed $value): ?string
    {
        $language = self::language($value);

        return $language !== null && in_array($language, self::supported(), true) ? $language : null;
    }

    /**
     * Reduce "en_US", "en-GB" or "EN" to the language code "en"; null when the value is not a locale at all.
     */
    public static function language(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $language = Str::lower(Str::before(str_replace('-', '_', trim($value)), '_'));

        return preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : null;
    }

    /**
     * Runs the callback with the translator and the dates in the given language, and puts the language back afterwards: what is made
     * for a person (an email, the line kept for their digest) is in their language, whoever caused it and whatever language that
     * person's own request was in.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function within(string $language, Closure $callback): mixed
    {
        $previous = app()->getLocale();

        app()->setLocale($language);
        Carbon::setLocale($language);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
            Carbon::setLocale($previous);
        }
    }
}
