<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the interface language for the request.
 *
 * Order: the signed-in user's locale preference, the language cookie, the
 * browser's Accept-Language header, then the application default. Only
 * languages listed in config('i18n.supported') are ever used.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $supported = $this->supported();

        $candidates = [
            $request->user()?->locale,
            $request->cookie((string) config('i18n.cookie', 'locale')),
        ];

        foreach ($candidates as $candidate) {
            $language = $this->language($candidate);

            if ($language !== null && in_array($language, $supported, true)) {
                return $language;
            }
        }

        $preferred = $this->language($request->getPreferredLanguage($supported));

        if ($preferred !== null && in_array($preferred, $supported, true)) {
            return $preferred;
        }

        return $this->default($supported);
    }

    /**
     * @return list<string>
     */
    private function supported(): array
    {
        $supported = [];

        foreach ((array) config('i18n.supported', ['en']) as $value) {
            $language = $this->language($value);

            if ($language !== null) {
                $supported[] = $language;
            }
        }

        return $supported !== [] ? array_values(array_unique($supported)) : ['en'];
    }

    /**
     * @param  list<string>  $supported
     */
    private function default(array $supported): string
    {
        $default = $this->language((string) config('app.locale', 'en'));

        return $default !== null && in_array($default, $supported, true) ? $default : $supported[0];
    }

    /**
     * Reduce "en_US", "en-GB" or "EN" to the language code "en".
     */
    private function language(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $language = Str::lower(Str::before(str_replace('-', '_', trim($value)), '_'));

        return preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : null;
    }
}
