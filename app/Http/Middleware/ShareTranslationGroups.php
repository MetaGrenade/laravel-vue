<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds language groups to the strings a route's pages receive, on top of `config('i18n.shared_groups')` which every page gets:
 * `->middleware('translations:auth')`. The sign-in pages are the only ones that need the words on them, so the other pages are not
 * made heavier by them (`HandleInertiaRequests` reads the groups from the request when it builds the page).
 *
 * The groups live on the request and nowhere else, so a worker that serves the next request has nothing left over (docs/octane.md).
 */
class ShareTranslationGroups
{
    /** Where the groups are kept on the request. */
    public const ATTRIBUTE = 'i18n.groups';

    /**
     * Groups named by the routes in front of this one are kept too, so that a group of routes and a route in it can each ask.
     */
    public function handle(Request $request, Closure $next, string ...$groups): Response
    {
        $request->attributes->set(self::ATTRIBUTE, array_values(array_unique([
            ...$this->groups($request),
            ...$groups,
        ])));

        return $next($request);
    }

    /**
     * @return list<string>
     */
    public static function groups(Request $request): array
    {
        return array_values((array) $request->attributes->get(self::ATTRIBUTE, []));
    }
}
