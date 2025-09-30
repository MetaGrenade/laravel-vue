<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSiteIsAvailable
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isMaintenanceMode = (bool) SystemSetting::get('maintenance_mode', false);

        if (! $isMaintenanceMode) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && $user->hasAnyRole(['admin', 'editor', 'moderator'])) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The application is currently undergoing maintenance.',
            ], 503);
        }

        return response()->view('maintenance', [], 503);
    }
}
