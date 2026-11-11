<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Localization\DateFormatter;
use App\Support\OAuth\OAuthProviders;
use App\Support\Security\BrowserSessions;
use App\Support\Security\SignInMethods;
use App\Support\Security\TwoFactorAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // Only the `database` session driver can say which sessions an account has; for any other the panel is not shown at all, since
        // the rows left in the table from before a switch would be listed as open browsers and ending one would end nothing.
        $canListSessions = BrowserSessions::canList();

        $sessions = $canListSessions
            ? BrowserSessions::table()
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
                ->values()
            : collect();

        $pendingSecret = null;
        $qrCodeUrl = null;

        if ($user->two_factor_secret && is_null($user->two_factor_confirmed_at)) {
            $pendingSecret = TwoFactorAuthenticator::decryptSecret($user->two_factor_secret);
            $qrCodeUrl = $pendingSecret ? TwoFactorAuthenticator::makeQrCodeUrl($user, $pendingSecret) : null;
        }

        // The codes are a second way into the account that outlasts a session, so the page does not carry them on every load: a browser
        // that was left signed in, or a person reading over a shoulder at the network tab, would have all of them. They are sent for the one
        // request after a set was made or after the password was asked for them (the flash is read, never kept); otherwise only how many
        // are left, which the prompt to replace a short set also says.
        $stored = $user->two_factor_recovery_codes;
        $stored = is_string($stored) ? TwoFactorAuthenticator::readRecoveryCodes($stored) : [];
        $recoveryCodes = $request->session()->get(TwoFactorAuthenticator::SHOW_RECOVERY_CODES) === true ? $stored : [];

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

        $providers = collect(OAuthProviders::availableOptions())
            ->map(function ($meta) {
                return [
                    'key' => $meta['key'],
                    'label' => $meta['label'] ?? ucfirst($meta['key']),
                    'description' => $meta['description'] ?? null,
                ];
            })
            ->values()
            ->all();

        // The secret of a setup that waits for its code (and the QR code made from it) and the recovery codes shown for this one request are
        // in the page, so the browser's copy of it, which Back shows again, is kept encrypted (and thrown away when the member signs out).
        // Only turned on here: when the operator has chosen to encrypt all history, HandleInertiaRequests has already done it.
        if ($pendingSecret !== null || $recoveryCodes !== []) {
            Inertia::encryptHistory();
        }

        return Inertia::render('settings/Security', [
            'sessions' => $sessions,
            'canListSessions' => $canListSessions,
            'twoFactorEnabled' => ! is_null($user->two_factor_secret),
            'twoFactorConfirmed' => ! is_null($user->two_factor_confirmed_at),
            // Starting a setup and confirming it ask for the password, unless the address the member has was made up by the site.
            'twoFactorSetupAsksForPassword' => SignInMethods::canBeAskedForPassword($user),
            'pendingSecret' => $pendingSecret,
            'qrCodeUrl' => $qrCodeUrl,
            'recoveryCodes' => $recoveryCodes,
            'recoveryCodesLeft' => count($stored),
            'status' => $request->session()->get('status'),
            'socialAccounts' => $socialAccounts,
            'availableSocialProviders' => $providers,
        ]);
    }
}
