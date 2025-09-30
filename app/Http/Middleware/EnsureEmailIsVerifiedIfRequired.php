<?php

namespace App\Http\Middleware;

use App\Support\EmailVerification;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerifiedIfRequired
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        if (! EmailVerification::isRequired()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || ($this->requiresVerification($user) && ! $this->hasVerifiedEmail($user))) {
            return $request->expectsJson()
                ? abort(Response::HTTP_FORBIDDEN, 'Your email address is not verified.')
                : Redirect::guest($this->redirectTo($request, $redirectToRoute));
        }

        return $next($request);
    }

    /**
     * Determine if the given user should be considered for verification.
     */
    protected function requiresVerification(object $user): bool
    {
        if ($user instanceof MustVerifyEmail) {
            return true;
        }

        return property_exists($user, 'email_verified_at') || method_exists($user, 'getAttribute');
    }

    /**
     * Determine if the given user has a verified email address.
     */
    protected function hasVerifiedEmail(object $user): bool
    {
        if (method_exists($user, 'hasVerifiedEmail')) {
            return (bool) $user->hasVerifiedEmail();
        }

        $emailVerifiedAt = null;

        if (method_exists($user, 'getAttribute')) {
            $emailVerifiedAt = $user->getAttribute('email_verified_at');
        } elseif (property_exists($user, 'email_verified_at')) {
            $emailVerifiedAt = $user->email_verified_at;
        }

        return ! is_null($emailVerifiedAt);
    }

    /**
     * Get the path the user should be redirected to when verification is required.
     */
    protected function redirectTo(Request $request, ?string $route): string
    {
        return $route ? route($route) : route('verification.notice');
    }
}
