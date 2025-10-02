<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PreventBannedUser
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_banned) {
            Auth::logout();

            if (method_exists($user, 'currentAccessToken') && ($token = $user->currentAccessToken())) {
                $token->delete();
            }

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                abort(403, trans('auth.banned'));
            }

            return redirect()->route('login')->withErrors([
                'email' => trans('auth.banned'),
            ]);
        }

        return $next($request);
    }
}
