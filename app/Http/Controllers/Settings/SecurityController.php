<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Localization\DateFormatter;
use App\Support\OAuth\OAuthProviders;
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
        $user = $request->user()->loadMissing('socialAccounts');

        $formatter = DateFormatter::for($user);

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($session) use ($formatter, $request) {
                $lastActive = Carbon::createFromTimestamp($session->last_activity);

                return [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'last_active_at' => $formatter->iso($lastActive),
                    'last_active_for_humans' => $formatter->human($lastActive),
                    'is_current_device' => $session->id === $request->session()->getId(),
                ];
            })
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

        $socialAccounts = $user->socialAccounts
            ->sortBy('provider')
            ->values()
            ->map(function ($account) use ($formatter) {
                return [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'provider_id' => $account->provider_id,
                    'name' => $account->name,
                    'nickname' => $account->nickname,
                    'email' => $account->email,
                    'avatar' => $account->avatar,
                    'linked_at' => $formatter->iso($account->created_at),
                    'updated_at' => $formatter->iso($account->updated_at),
                ];
            })
            ->all();

        $providers = collect(OAuthProviders::enabledOptions())
            ->map(function ($meta) {
                return [
                    'key' => $meta['key'],
                    'label' => $meta['label'] ?? ucfirst($meta['key']),
                    'description' => $meta['description'] ?? null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('settings/Security', [
            'sessions' => $sessions,
            'twoFactorEnabled' => ! is_null($user->two_factor_secret),
            'twoFactorConfirmed' => ! is_null($user->two_factor_confirmed_at),
            'pendingSecret' => $pendingSecret,
            'qrCodeUrl' => $qrCodeUrl,
            'recoveryCodes' => $recoveryCodes,
            'status' => $request->session()->get('status'),
            'socialAccounts' => $socialAccounts,
            'availableSocialProviders' => $providers,
        ]);
    }
}
