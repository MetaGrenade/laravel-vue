<?php

namespace App\Support\Security;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * What only an administrator may do (docs/acp-permissions.md): give or take the administrator role, change the role itself, and change
 * an administrator's account or anything that signs in as it. A permission handed to somebody else (`users.acp.edit`,
 * `tokens.acp.create`...) opens its own part of the admin area, and is never a way to become an administrator or to take one's place.
 *
 * Each check refuses with a 403 that says which of these it was. Warning, banning and two-factor have their own rule, which covers all
 * staff (App\Support\Moderation\Moderation, TwoFactorReset).
 */
final class Administrators
{
    public const ROLE = 'admin';

    public const ACCOUNT = "Only an administrator can change an administrator's account.";

    public const GRANT = 'Only an administrator can give the administrator role.';

    public const THE_ROLE = 'Only an administrator can change or delete the administrator role.';

    public const MAPPING = 'Only an administrator can change what the administrator role is mapped to.';

    public static function is(?User $user): bool
    {
        return $user !== null && $user->hasRole(self::ROLE);
    }

    /**
     * Whether one of these names means the administrator role. The database is asked as well as PHP, because it is what finds a role
     * by its name when one is given, and on MariaDB and MySQL "Admin" finds "admin".
     */
    public static function named(string ...$names): bool
    {
        if ($names === []) {
            return false;
        }

        foreach ($names as $name) {
            if (mb_strtolower(trim($name)) === self::ROLE) {
                return true;
            }
        }

        // Is the administrator role among those these names find?
        return Role::query()->where('name', self::ROLE)->whereIn('name', $names)->exists();
    }

    /** Whether the actor may change this account: anybody's but an administrator's, unless the actor is one. */
    public static function mayChange(?User $actor, mixed $account): bool
    {
        return ! $account instanceof User || ! self::is($account) || self::is($actor);
    }

    public static function authorizeAccount(?User $actor, mixed $account): void
    {
        abort_unless(self::mayChange($actor, $account), 403, self::ACCOUNT);
    }

    /**
     * For the roles an account is about to be given.
     *
     * @param  array<int, string>  $roles
     */
    public static function authorizeGrant(?User $actor, array $roles): void
    {
        abort_if(! self::is($actor) && self::named(...array_values($roles)), 403, self::GRANT);
    }

    /** For a role about to be changed or deleted, and for the name a role is about to be given. */
    public static function authorizeRole(?User $actor, string ...$names): void
    {
        abort_if(! self::is($actor) && self::named(...$names), 403, self::THE_ROLE);
    }

    /** For a link between the administrator role and something outside the site that hands it out (a Discord role). */
    public static function authorizeMapping(?User $actor, string ...$names): void
    {
        abort_if(! self::is($actor) && self::named(...$names), 403, self::MAPPING);
    }
}
