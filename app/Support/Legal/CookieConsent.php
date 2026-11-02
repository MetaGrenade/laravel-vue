<?php

namespace App\Support\Legal;

use Illuminate\Http\Request;

/**
 * The visitor's cookie choice (docs/legal-pages.md).
 *
 * Necessary cookies (the session, this choice) are always set. Anything optional stays off until the choice is "all".
 * Preference cookies (the theme and the side menu) are written by the front end only when the visitor changes that setting,
 * and they do not wait for the choice: the banner and the cookie policy say so, and a test keeps that true.
 *
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
