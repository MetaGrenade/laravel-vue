<?php

namespace App\Http\Middleware;

use App\Support\Localization\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the interface language for the request.
 *
 * Order: the signed-in user's locale preference, the language cookie, the
 * browser's Accept-Language header, then the application default. Only
 * languages listed in config('i18n.supported') are ever used (`Locales`).
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
        $candidates = [
            $request->user()?->locale,
            $request->cookie((string) config('i18n.cookie', 'locale')),
        ];

        foreach ($candidates as $candidate) {
            $language = Locales::supportedLanguage($candidate);

            if ($language !== null) {
                return $language;
            }
        }

        $preferred = Locales::supportedLanguage($request->getPreferredLanguage(Locales::supported()));

        return $preferred ?? Locales::default();
    }
}
