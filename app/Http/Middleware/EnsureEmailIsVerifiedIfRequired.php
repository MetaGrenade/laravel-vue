<?php

namespace App\Http\Middleware;

use App\Support\EmailVerification;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as BaseMiddleware;

class EnsureEmailIsVerifiedIfRequired extends BaseMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        if (! EmailVerification::isRequired()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
