<?php

namespace App\Http\Middleware;

use App\Support\Audit\Audit;
use App\Support\Errors\ErrorPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes an audit log entry for every change somebody signed in makes in the admin area: a POST, PUT, PATCH or DELETE to an address
 * under `acp/` (see docs/audit-log.md).
 *
 * It recognises the address, not the route group it was defined in, so a route added to the admin area later is covered without
 * anyone having to remember to tag it. Looking at a page is not recorded, and nor is a request nobody was signed in for (that is a
 * bot, not an admin) or one that matches no route.
 */
class AuditAdminActions
{
    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAdminChange($request)) {
            return $next($request);
        }

        $audit = app(Audit::class);
        $audit->begin($request->user());

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // The router turns exceptions into responses before they get here, so this is only for one that gets past it.
            $audit->finish($request, 500);

            throw $exception;
        }

        // A refusal inside the app is answered with a redirect back to the page, so the status it had is asked for: a 403 is a denial.
        $audit->finish($request, ErrorPages::statusOf($request, $response));

        return $response;
    }

    private function isAdminChange(Request $request): bool
    {
        return in_array($request->method(), self::WRITES, true)
            && $request->is('acp/*')
            && $request->route() !== null;
    }
}
