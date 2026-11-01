<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Support\Header;
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

        // A visit inside the app cannot show a page of its own: the client would put this one in a dialog over the page it was on.
        // Telling it to load the address in full shows the maintenance page as a page.
        if ($request->header(Header::INERTIA)) {
            return Inertia::location($request->fullUrl());
        }

        return response()->view('maintenance', [], 503);
    }
}
