<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Security\BrowserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecuritySessionController extends Controller
{
    /**
     * Revoke an active session.
     */
    public function destroy(Request $request, string $sessionId): RedirectResponse
    {
        $currentSessionId = $request->session()->getId();

        if ($sessionId === $currentSessionId) {
            return back()->with('status', 'current-session-retained');
        }

        // The list is only shown when the driver can name sessions. A request for one anyway ends nothing, and says so.
        if (! BrowserSessions::canList()) {
            return back()->with('status', 'sessions-not-listed');
        }

        BrowserSessions::forget($request->user(), $sessionId);

        return back()->with('status', 'session-revoked');
    }

    /**
     * Sign out every browser but this one, with whatever session driver the site uses.
     *
     * The password is asked for because this is the lever a thief's session is pulled with, and the one who pulls it must be the owner.
     * It re-hashes the password: `AuthenticateSession` compares each session with the account's current hash, so every other browser is
     * signed out when it next asks for a page, and this one is given the new hash. No list of sessions is needed, so it works on Redis.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        Auth::logoutOtherDevices($validated['password']);

        // With the database driver the rows go now, so the list is true at once and not when each browser next asks for a page.
        BrowserSessions::forgetOthers($request->user(), $request->session()->getId());

        return back()->with('status', 'other-sessions-ended');
    }
}
