<?php

namespace App\Http\Controllers;

use App\Support\Legal\CookieConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Records the banner's choice (docs/legal-pages.md).
 */
class CookieConsentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'choice' => ['required', 'string', Rule::in([CookieConsent::NECESSARY, CookieConsent::ALL])],
        ]);

        $choice = $validated['choice'];

        return back()->withCookie(cookie(
            CookieConsent::NAME,
            is_string($choice) ? $choice : CookieConsent::NECESSARY,
            CookieConsent::MINUTES,
            '/',
            null,
            null,
            true,
            false,
            'lax',
        ));
    }
}
