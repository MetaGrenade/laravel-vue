<?php

namespace App\Support\Security;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\OAuth\OAuthProviders;

/**
 * Whether a member can still get into their account, asked before a way in is taken away.
 *
 * A member who signed up with Google, Discord or Steam has a password they were never shown (a random one) and, for Steam and for a
 * Discord account with no address, an email address that only looks like one (`discord_123@oauth.local`) and receives nothing. If they
 * unlink the provider, nothing is left to sign in with, and nobody can reset the password. The site cannot tell a password the member chose
 * from one nobody knows (it keeps no such fact), so what counts as a way in is what it can check:
 *
 *  1. another linked provider that is switched on, or
 *  2. a real email address that the member has verified. Then "Forgot your password?" always works, whether or not they ever chose one.
 *
 * Anything else, and unlinking would lock the account.
 *
 * See docs/social-login.md ("Unlinking") for the page that says this to members and to the people who run the site.
 */
class SignInMethods
{
    /** The domain of the made-up address an account gets when its provider gave none, or one that somebody else already has. */
    public const PLACEHOLDER_DOMAIN = 'oauth.local';

    /**
     * An address the site made up. It cannot receive mail, so it is no way to reset a password.
     */
    public static function isPlaceholderEmail(?string $email): bool
    {
        return is_string($email) && str_ends_with(strtolower($email), '@'.self::PLACEHOLDER_DOMAIN);
    }

    /**
     * Whether a step that asks for the current password (changing the address, starting a two-factor setup) asks this member. Everybody
     * is asked except a member whose address the site made up: they have no password they were shown to give, so asking would shut them
     * out of a step that cannot be done any other way. (A member who signed up with a provider and has a real address is asked, and can
     * give one after "Forgot your password?": the site cannot tell whether they ever chose one, see above.)
     */
    public static function canBeAskedForPassword(User $user): bool
    {
        return ! self::isPlaceholderEmail($user->email);
    }

    /**
     * Whether the member could sign in again after this linked account is removed.
     */
    public static function remainAfterUnlinking(User $user, SocialAccount $account): bool
    {
        $anotherProvider = $user->socialAccounts()
            ->whereKeyNot($account->getKey())
            ->pluck('provider')
            ->contains(fn (string $provider): bool => OAuthProviders::isAvailable($provider));

        return $anotherProvider || self::canResetByEmail($user);
    }

    /**
     * A real address that the member has confirmed is theirs, so a reset link sent to it reaches them and only them.
     */
    public static function canResetByEmail(User $user): bool
    {
        return $user->email_verified_at !== null
            && $user->email !== ''
            && ! self::isPlaceholderEmail($user->email);
    }
}
