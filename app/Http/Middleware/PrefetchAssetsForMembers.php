<?php

namespace App\Http\Middleware;

use App\Support\Frontend\StaffAreaVite;
use App\Support\Routing\ZiggyRouteGroup;
use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a page has drawn, the browser fetches the rest of the front end in the background (`Vite::prefetch`: the pages of the site,
 * three files at a time), so moving between pages is quick. A member will move around the site; a visitor often reads one page and
 * leaves, and has no use for most of what was fetched. So only a signed-in user gets it, and everybody else gets pages that fetch
 * what they show (docs/bundle-budget.md).
 *
 * The staff area's pages are about 30 % of what is fetched, and a member who is not staff is refused every one of them, so they are
 * fetched for staff only (`StaffAreaVite`).
 *
 * It is set on every request, both ways: Vite is bound once for the container and what one request sets would otherwise still be there
 * for the next, and a visitor served after a member would be prefetching (docs/octane.md; the binding is `scoped` too, for a worker
 * that does not reset the container between requests).
 */
class PrefetchAssetsForMembers
{
    /** How many files are fetched at a time. */
    private const CONCURRENCY = 3;

    public function __construct(private readonly Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            $this->vite->usePrefetchStrategy(null);
        } else {
            $this->vite->prefetch(concurrency: self::CONCURRENCY);
        }

        // Whatever else is bound as Vite has no staff area to leave out: in tests, `withoutVite()` puts a fake there.
        if ($this->vite instanceof StaffAreaVite) {
            $this->vite->withoutStaffAreaPages($user !== null && ! $user->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES));
        }

        return $next($request);
    }
}
