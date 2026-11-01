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

        // A request that starts no session (a document, app/Support/Routing/PublicDocuments.php) has nobody signed in to let through.
        // Asking the guard anyway could sign a member in from a remember-me cookie, with nothing to keep it in.
        $user = $request->hasSession() ? $request->user() : null;

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
