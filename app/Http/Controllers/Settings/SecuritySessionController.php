<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->delete();

        return back()->with('status', 'session-revoked');
    }
}
