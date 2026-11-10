<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetTwoFactorRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Security\TwoFactorReset;
use Illuminate\Http\RedirectResponse;

/**
 * Switching two-factor off for a member who is locked out (see docs/two-factor.md, "When a member is locked out"). Who may is the
 * route's `can:users.acp.reset-two-factor`; whether they may do it to this account is `TwoFactorReset::refusal()`.
 */
class UserTwoFactorController extends Controller
{
    public function destroy(ResetTwoFactorRequest $request, User $user, TwoFactorReset $reset, Audit $audit): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Named before anything can be refused, so an attempt that did not work is in the log under the same name as one that did (its
        // outcome says which), with the reason that was given. (What it was done to is the account the address names.)
        $audit->event('users.two-factor.reset');
        $audit->note('reason', (string) $request->validated('reason'));

        $refusal = $reset->refusal($actor, $user);

        if ($refusal !== null) {
            return back()->withErrors(['two_factor' => $refusal]);
        }

        $told = $reset->reset($user, $actor, (string) $request->validated('reason'), $request);

        return redirect()->route('acp.users.edit', $user)->with(
            'success',
            $told
                ? 'Two-factor was turned off for this account, and the member was emailed.'
                : 'Two-factor was turned off for this account. The member was not emailed: their address cannot receive mail.',
        );
    }
}
