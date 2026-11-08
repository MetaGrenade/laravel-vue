<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `password.confirm`, but only for an account whose second factor is on.
 *
 * Switching two-factor off takes a protection away, so it asks for the password (docs/two-factor.md, "Turning it off"). Cancelling a
 * setup that was never confirmed takes nothing away, since the second factor was never asked for at sign-in, and it must stay one click.
 * The route is the same for both, so the decision cannot be made by the route alone.
 */
class RequirePasswordWhenTwoFactorIsOn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->two_factor_confirmed_at) {
            return $next($request);
        }

        return app(RequirePassword::class)->handle($request, $next);
    }
}
