<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only when the signed-in user holds every entitlement named: `entitled:team.seats` or
 * `entitled:reports,exports`. A feature must be on and a limit above nothing. A guest is sent to sign in; a user who
 * does not hold one gets a 403.
 *
 * It asks for nothing to be named at all as a mistake (it would let everyone in), so that fails loudly.
 */
class EnsureEntitled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        if ($keys === []) {
            throw new InvalidArgumentException('The entitled middleware needs at least one entitlement key, for example entitled:reports.');
        }

        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        $held = $user->entitlementSet();

        foreach ($keys as $key) {
            abort_unless($held->has($key), 403, 'Your account does not include this.');
        }

        return $next($request);
    }
}
