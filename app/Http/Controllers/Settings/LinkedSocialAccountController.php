<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\OAuth\ProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LinkedSocialAccountController extends Controller
{
    /**
     * Disconnect a linked social provider from the authenticated user.
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

        $account->delete();

        return redirect()->route('security.edit')->with('status', 'social-account-unlinked');
    }
}
