<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\OAuth\ProviderRegistry;
use App\Support\Security\SignInMethods;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LinkedSocialAccountController extends Controller
{
    /**
     * Disconnect a linked social provider from the authenticated user, unless that would leave them with no way to sign in
     * (see {@see SignInMethods}).
     */
    public function destroy(Request $request, string $provider): RedirectResponse
    {
        $provider = strtolower($provider);

        if (! ProviderRegistry::supports($provider)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $account = $request->user()
            ->socialAccounts()
            ->where('provider', $provider)
            ->first();

        if (! $account) {
            return redirect()->route('security.edit')->with('status', 'social-account-missing');
        }

        if (! SignInMethods::remainAfterUnlinking($request->user(), $account)) {
            return redirect()->route('security.edit')->with('status', 'social-account-last-sign-in');
        }

        $account->delete();

        return redirect()->route('security.edit')->with('status', 'social-account-unlinked');
    }
}
