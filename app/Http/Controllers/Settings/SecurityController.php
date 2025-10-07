<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Display the security settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($session) => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                'last_active_for_humans' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                'is_current_device' => $session->id === $request->session()->getId(),
            ])
            ->values();

        $pendingSecret = null;
        $qrCodeUrl = null;

        if ($user->two_factor_secret && is_null($user->two_factor_confirmed_at)) {
            $pendingSecret = TwoFactorAuthenticator::decryptSecret($user->two_factor_secret);
            $qrCodeUrl = $pendingSecret ? TwoFactorAuthenticator::makeQrCodeUrl($user, $pendingSecret) : null;
        }

        $recoveryCodes = [];

        if ($user->two_factor_recovery_codes) {
            try {
                $recoveryCodes = TwoFactorAuthenticator::decryptRecoveryCodes($user->two_factor_recovery_codes);
            } catch (JsonException) {
                $recoveryCodes = [];
            }
        }

        return Inertia::render('settings/Security', [
            'sessions' => $sessions,
            'twoFactorEnabled' => ! is_null($user->two_factor_secret),
            'twoFactorConfirmed' => ! is_null($user->two_factor_confirmed_at),
            'pendingSecret' => $pendingSecret,
            'qrCodeUrl' => $qrCodeUrl,
            'recoveryCodes' => $recoveryCodes,
            'status' => $request->session()->get('status'),
        ]);
    }
}
