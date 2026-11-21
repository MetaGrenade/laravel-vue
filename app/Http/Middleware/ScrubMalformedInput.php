<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bytes that are not valid UTF-8 in the query string or the form body (`?search=%FF`, which a browser never sends but a link
 * typed by hand, an old page in another encoding or a scanner does) are replaced with `?` before anything reads them.
 *
 * Nothing downstream can hold them: a JSON response or an Inertia page that echoes the text back (`filters.search`, the
 * `query` of the live search) cannot be encoded, so the live search answered 500 and the lists of blog posts, members and
 * support, and a dozen admin lists, came back with their page data empty, which is a blank screen. PostgreSQL also refuses such
 * a string in a query. Replacing is kinder than refusing: the page is still the page, and a link from an old site in Latin-1
 * (`?q=caf%E9`) keeps working.
 *
 * Only what PHP decoded into the query and the form body is touched; a JSON body that is not UTF-8 is already empty by the
 * time Laravel reads it, and the address (the route's own parameters) is not changed.
 */
class ScrubMalformedInput
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach ([$request->query, $request->request] as $bag) {
            $this->scrub($bag);
        }

        return $next($request);
    }

    private function scrub(ParameterBag $bag): void
    {
        $all = $bag->all();

        if ($all === [] || mb_check_encoding($all, 'UTF-8')) {
            return;
        }

        $bag->replace(self::clean($all));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function clean(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $clean[is_string($key) ? mb_scrub($key, 'UTF-8') : $key] = match (true) {
                is_string($value) => mb_scrub($value, 'UTF-8'),
                is_array($value) => self::clean($value),
                default => $value,
            };
        }

        return $clean;
    }
}
