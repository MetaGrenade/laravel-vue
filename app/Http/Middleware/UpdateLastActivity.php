<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UpdateLastActivity
{
    public function handle(Request $request, Closure $next)
    {
        if ($user = Auth::user()) {
            $user->last_activity_at = now();
            $user->saveQuietly(); // avoids firing extra events
        }

        return $next($request);
    }
}
