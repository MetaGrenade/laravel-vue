<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a page has drawn, the browser fetches the rest of the front end in the background (`Vite::prefetch`: every page of the site, the
 * staff area included, about 990 kB gzipped, three files at a time), so moving between pages is quick. A member will move around the
 * site; a visitor often reads one page and leaves, and has no use for most of what was fetched. So only a signed-in user gets it, and
 * everybody else gets pages that fetch what they show (docs/bundle-budget.md).
 *
 * It is set on every request, both ways: `Vite` is a singleton, so on a worker that serves many requests (Octane, docs/octane.md) what
 * one request sets would still be there for the next, and a visitor served after a member would be prefetching.
 */
class PrefetchAssetsForMembers
{
    /** How many files are fetched at a time. */
    private const CONCURRENCY = 3;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            Vite::prefetch(concurrency: self::CONCURRENCY);
        } else {
            Vite::usePrefetchStrategy(null);
        }

        return $next($request);
    }
}
