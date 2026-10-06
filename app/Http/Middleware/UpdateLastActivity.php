<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastActivity
{
    /**
     * Minimum number of seconds between writes for the same user, so busy
     * sessions don't write to the users table on every request.
     */
    private const INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ($user->last_activity_at === null || $user->last_activity_at->lt(now()->subSeconds(self::INTERVAL_SECONDS)))) {
            $now = now();

            // A direct query avoids touching updated_at and firing model events.
            $user->newQueryWithoutScopes()->whereKey($user->getKey())->toBase()->update(['last_activity_at' => $now]);

            $user->setAttribute('last_activity_at', $now);
            $user->syncOriginalAttribute('last_activity_at');
        }

        return $next($request);
    }
}
