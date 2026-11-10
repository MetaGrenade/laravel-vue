<?php

namespace App\Support\Security;

use App\Models\User;

/**
 * Changing the email address of an account (Settings → Profile).
 *
 * The address is where "Forgot your password?" sends its link, so whoever can change it from a session that was left open, or taken, can
 * ask for a reset at the new address and own the account, while the person it belonged to is left with an address that no longer reaches
 * anything. A change therefore asks for the current password, as turning two-factor off does (docs/two-factor.md), and the old address is
 * told afterwards (`EmailAddressChanged`).
 *
 * The one member who is not asked is one whose address was made up by the site (`SignInMethods::isPlaceholderEmail`): they have no
 * password they were shown to give, and the change is how they get an address that can receive a reset link at all.
 */
final class EmailChange
{
    /**
     * Whether the address in a request is not the one the account has (the comparison the form makes: spaces and case do not count).
     */
    public static function isChange(User $user, mixed $requested): bool
    {
        return is_string($requested) && self::normalise($requested) !== self::normalise((string) $user->email);
    }

    /**
     * Whether this member is asked for the password to change the address: not when the address they have cannot receive mail.
     */
    public static function asksForPassword(User $user): bool
    {
        return SignInMethods::canBeAskedForPassword($user);
    }

    /**
     * Whether this request has to carry the password.
     */
    public static function needsPassword(User $user, mixed $requested): bool
    {
        return self::asksForPassword($user) && self::isChange($user, $requested);
    }

    /**
     * An address with most of the name left out, as it can be said to somebody who is not sure it was them: `j***@example.com`.
     */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', trim($email), 2), 2, '');

        if ($domain === '') {
            return str_repeat('*', 3);
        }

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    private static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
