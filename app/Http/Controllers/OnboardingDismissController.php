<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Onboarding\Onboarding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Hiding the "Get started" checklist on the dashboard, for good (docs/onboarding.md).
 */
class OnboardingDismissController extends Controller
{
    public function __invoke(Request $request, Onboarding $onboarding): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $onboarding->dismiss($user);

        return redirect()->route('dashboard');
    }
}
