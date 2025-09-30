<?php

namespace App\Http\Middleware;

use App\Support\EmailVerification;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as BaseMiddleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerifiedIfRequired extends BaseMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        if (! EmailVerification::isRequired()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
