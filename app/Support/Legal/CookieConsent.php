<?php

namespace App\Support\Legal;

use Illuminate\Http\Request;

/**
 * The visitor's cookie choice (docs/legal-pages.md).
 *
 * Necessary cookies (the session, this choice) are always set. Anything optional stays off until the choice is "all".
 * Nothing on the site calls {@see allowsOptional()} yet, because there is no optional script. Checkout stays available
 * either way: paying is not optional.
 */
class CookieConsent
{
    public const NAME = 'cookie_consent';

    public const NECESSARY = 'necessary';

    public const ALL = 'all';

    /** Half a year. */
    public const MINUTES = 60 * 24 * 180;

    public static function choice(Request $request): ?string
    {
        $value = $request->cookie(self::NAME);

        return in_array($value, [self::NECESSARY, self::ALL], true) ? $value : null;
    }

    public static function allowsOptional(Request $request): bool
    {
        return self::choice($request) === self::ALL;
    }
}
