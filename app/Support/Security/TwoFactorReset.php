<?php

namespace App\Support\Security;

use App\Models\User;
use App\Notifications\TwoFactorWasReset;
use App\Support\Audit\Audit;
use App\Support\Routing\ZiggyRouteGroup;
use Illuminate\Http\Request;
use Throwable;

/**
 * Switching two-factor off for a member who cannot get past it (they lost the authenticator app and the recovery codes), done by
 * somebody else: staff, from the admin area, or whoever runs the site, from the console. Without it a member in that position has no way
 * back from the site at all (docs/two-factor.md, "When a member is locked out").
 *
 * It takes a protection away from an account on somebody's word, so it is not quiet:
 *  - it needs its own permission (`users.acp.reset-two-factor`), which administrators have and moderators do not;
 *  - it says why, and the reason is kept in the audit log with who did it and to whom;
 *  - the member is emailed that it happened, whatever their notification settings say, so that somebody who did not ask for it finds out.
 *
 * What it does not do is vouch for the person asking. Whoever does it has to be sure the member is who they say, the way they would
 * be before changing the address on an account: the password is still needed to sign in afterwards, which is what limits the harm.
 */
class TwoFactorReset
{
    /**
     * Why this person may not do it to this member (oneself, an administrator's account when not an administrator, or nothing to reset),
     * or null when they may. Checked by the page, to say why a button is off, and again by whatever does it.
     */
    public function refusal(User $actor, User $member): ?string
    {
        // Switching it off for oneself is what Settings → Security is for, and it asks for the password. Here it would be a way round
        // that for anybody who holds an administrator's session.
        if ($actor->is($member)) {
            return 'You cannot reset your own two-factor here. Turn it off in Settings → Security, which asks for your password.';
        }

        if ($member->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES) && ! $actor->hasRole('admin')) {
            return 'Only an administrator can reset two-factor for a member of staff.';
        }

        if (! self::isSetUp($member)) {
            return 'Two-factor is not set up for this account.';
        }

        return null;
    }

    /**
     * Whether there is anything to reset: a confirmed setup, or one that was started and never confirmed.
     */
    public static function isSetUp(User $member): bool
    {
        return $member->two_factor_secret !== null || $member->two_factor_confirmed_at !== null || $member->two_factor_recovery_codes !== null;
    }

    /**
     * Switch it off: the secret, the confirmation and the recovery codes go, so sign-in asks for the password alone again. The caller has
     * checked `refusal()` (or is the console, where whoever runs the command is trusted with the site).
     *
     * Returns whether the member was told by email: not when the address is one the site made up, which receives nothing.
     *
     * @param  Request|null  $request  The admin request, whose audit entry then says it (the console has none).
     */
    public function reset(User $member, ?User $actor, string $reason, ?Request $request = null): bool
    {
        $was = $member->two_factor_confirmed_at !== null ? 'confirmed' : 'started';

        $member->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $this->record($member, $actor, $reason, $was, $request);

        return $this->tell($member);
    }

    /**
     * An admin request already has its entry open (`AuditAdminActions`), and its controller named it and gave the reason before the
     * request could be refused (so that a refusal is in the log under the same name), so only what is known here is added. Anything else,
     * the console, has none, so one is written here. Either way the entry says who, to whom, and why.
     */
    private function record(User $member, ?User $actor, string $reason, string $was, ?Request $request): void
    {
        $audit = app(Audit::class);

        if ($audit->recording()) {
            $audit->note('was', $was);

            return;
        }

        $audit->record('users.two-factor.reset', $actor, $member, meta: ['reason' => $reason, 'was' => $was, 'from' => 'console'], request: $request);
    }

    /**
     * Email the member. A mail that cannot be queued is reported and does not undo the reset: they cannot get in without it.
     */
    private function tell(User $member): bool
    {
        if (SignInMethods::isPlaceholderEmail($member->email) || ! filled($member->email)) {
            return false;
        }

        try {
            $member->notify(new TwoFactorWasReset(now()));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }
}
