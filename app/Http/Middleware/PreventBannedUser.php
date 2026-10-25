<?php

namespace App\Http\Middleware;

use App\Support\Moderation\Moderation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PreventBannedUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // A suspension that has run out ends here, so it is the moment it ends and not up to a minute after.
        if ($user !== null) {
            app(Moderation::class)->liftIfRunOut($user);
        }

        if ($user?->is_banned) {
            // An API key is refused here on every request while the ban lasts, and not deleted: a ban can end, and a member's tools should
            // work again when it does. There is only a session to end when the request has one; the guard of an API key has no `logout()`.
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $message = app(Moderation::class)->banMessage($user);

            if ($request->expectsJson()) {
                abort(403, $message);
            }

            return redirect()->route('login')->withErrors([
                'email' => $message,
            ]);
        }

        return $next($request);
    }
}
