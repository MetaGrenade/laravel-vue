<?php

namespace App\Http\Middleware;

use App\Support\Security\SignInMethods;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `password.confirm`, for the two steps that set two-factor up: starting a setup, and confirming it with a code.
 *
 * Turning two-factor on looks harmless, since it takes nothing away. It is not: from a browser that was left signed in, or whose session
 * was taken, it puts the *attacker's* authenticator on the account. The owner then meets a code prompt they cannot answer at every sign-in
 * (the attacker has the recovery codes the confirmation shows), and the site has no way for staff to reset it. So a setup asks for the
 * password, the same as switching it off does (docs/two-factor.md, "Turning it on").
 *
 * Both steps ask because either one finishes the job: the secret of a setup that was started is on the Security page until it is
 * confirmed, so a session that did not start it can still finish it.
 *
 * A member whose address the site made up is not asked (`SignInMethods::canBeAskedForPassword`): they have no password they were shown,
 * and for them this is the only way to use two-factor at all.
 */
class RequirePasswordToSetUpTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! SignInMethods::canBeAskedForPassword($user)) {
            return $next($request);
        }

        return app(RequirePassword::class)->handle($request, $next);
    }
}
