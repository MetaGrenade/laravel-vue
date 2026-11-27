<?php

namespace App\Support\Security;

use App\Models\User;
use App\Support\Routing\ZiggyRouteGroup;
use Illuminate\Support\Facades\DB;
use MetaForge\Discord\Models\DiscordRoleMapping;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * What only an administrator may do (docs/acp-permissions.md). A permission handed to somebody else (`users.acp.edit`,
 * `acl.acp.edit`, `tokens.acp.create`...) opens its own part of the admin area, inside two limits:
 *
 *  - a staff account (see Staff), anything that signs in as it, and the admin, editor and moderator roles are an administrator's to
 *    change;
 *  - anybody else gives only permissions they hold themselves, and takes none away from somebody who has them.
 *
 * Each check refuses with a 403 that says which of these it was, and passes an administrator without looking. Warning, banning,
 * two-factor and viewing as somebody have rules of their own, which keep all staff from somebody who is not an administrator too
 * (App\Support\Moderation\Moderation, TwoFactorReset, Impersonation).
 */
final class Administrators
{
    public const ROLE = 'admin';

    public const ACCOUNT = 'Only an administrator can change a staff account.';

    public const GRANT = 'Only an administrator can give the admin, editor or moderator role.';

    public const THE_ROLE = 'Only an administrator can change or delete the admin, editor and moderator roles.';

    public const MAPPING = 'Only an administrator can change what the admin, editor and moderator roles are mapped to.';

    public const NOT_HELD = 'You can only give permissions you hold yourself.';

    public const RENAME = 'Only an administrator can rename a role or a permission, or change its guard.';

    public const ROLE_IN_USE = 'Somebody has this role, so only an administrator can take permissions off it or delete it.';

    public const PERMISSION_IN_USE = 'This permission is in use, so only an administrator can delete it.';

    public const NO_SUCH_ROLE = 'Only an administrator can map a role that does not exist.';

    public const PROVIDER = 'Discord gives roles to the account linked to it, some of which you cannot give. An administrator has to link it.';

    public const INBOUND = 'Syncing from Discord takes a role from accounts without the Discord role. Somebody has this one, so ask an administrator.';

    public static function is(?User $user): bool
    {
        return $user !== null && $user->hasRole(self::ROLE);
    }

    /**
     * Whether one of these names means the admin, editor or moderator role. The database is asked as well as PHP, because it is what
     * finds a role by its name when one is given, and on MariaDB and MySQL "Admin" finds "admin".
     */
    public static function named(string ...$names): bool
    {
        if ($names === []) {
            return false;
        }

        foreach ($names as $name) {
            if (self::isStaffRole($name)) {
                return true;
            }
        }

        // Is one of those roles among those these names find?
        return Role::query()->whereIn('name', ZiggyRouteGroup::STAFF_ROLES)->whereIn('name', $names)->exists();
    }

    /** Whether this is the name of the admin, editor or moderator role, as PHP reads it: for a name that is a role's own. */
    public static function isStaffRole(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), ZiggyRouteGroup::STAFF_ROLES, true);
    }

    /** Whether the actor may change this account: a member's, or anybody's if the actor is an administrator. */
    public static function mayChange(?User $actor, mixed $account): bool
    {
        return ! $account instanceof User || self::is($actor) || ! Staff::is($account);
    }

    public static function authorizeAccount(?User $actor, mixed $account): void
    {
        abort_unless(self::mayChange($actor, $account), 403, self::ACCOUNT);
    }

    /**
     * For the roles an account is about to have. Those it has already are not being given.
     *
     * @param  array<int, string>  $roles
     */
    public static function authorizeGrant(?User $actor, User $account, array $roles): void
    {
        if (self::is($actor)) {
            return;
        }

        abort_if(self::named(...array_values($roles)), 403, self::GRANT);

        $account->loadMissing('roles');
        $given = Role::query()->with('permissions')->whereIn('name', $roles)->whereKeyNot($account->roles->modelKeys())->get();

        abort_unless($given->every(fn (Role $role): bool => self::holdsAllOf($actor, $role)), 403, self::NOT_HELD);
    }

    /**
     * The roles the actor may not give anybody, for a page to switch off. None for an administrator.
     *
     * @return array<int, string>
     */
    public static function rolesNotGivenBy(?User $actor): array
    {
        if (self::is($actor)) {
            return [];
        }

        return Role::query()->with('permissions')->orderBy('name')->get()
            ->reject(fn (Role $role): bool => self::mayGive($actor, $role))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * The names of the permissions the actor may give, for a page to switch the rest off: null for an administrator, who may give any.
     *
     * @return array<int, string>|null
     */
    public static function permissionsGivenBy(?User $actor): ?array
    {
        return self::is($actor) ? null : self::held($actor);
    }

    /**
     * For a role about to be made.
     *
     * @param  array<int, string>  $permissions
     */
    public static function authorizeNewRole(?User $actor, string $name, array $permissions): void
    {
        if (self::is($actor)) {
            return;
        }

        abort_if(self::named($name), 403, self::THE_ROLE);
        abort_unless(self::holds($actor, $permissions), 403, self::NOT_HELD);
    }

    /**
     * For a role about to be saved, with the name, guard and permissions it is to have.
     *
     * @param  array<int, string>  $permissions
     */
    public static function authorizeRoleChange(?User $actor, Role $role, string $name, string $guard, array $permissions): void
    {
        if (self::is($actor)) {
            return;
        }

        abort_if(self::named($role->name), 403, self::THE_ROLE);
        // The app knows an administrator by the role's name, so no other role is renamed into it either.
        abort_if($name !== $role->name || $guard !== $role->guard_name, 403, self::RENAME);

        $has = $role->permissions->pluck('name')->all();

        abort_unless(self::holds($actor, array_values(array_diff($permissions, $has))), 403, self::NOT_HELD);
        abort_if(array_diff($has, $permissions) !== [] && self::rolesInUse([$role->id]) !== [], 403, self::ROLE_IN_USE);
    }

    public static function authorizeRoleDeletion(?User $actor, Role $role): void
    {
        if (self::is($actor)) {
            return;
        }

        abort_if(self::named($role->name), 403, self::THE_ROLE);
        abort_if(self::rolesInUse([$role->id]) !== [], 403, self::ROLE_IN_USE);
    }

    /** For a permission about to be given another name or guard: the app knows what it allows by its name. */
    public static function authorizePermissionChange(?User $actor): void
    {
        abort_unless(self::is($actor), 403, self::RENAME);
    }

    public static function authorizePermissionDeletion(?User $actor, Permission $permission): void
    {
        abort_if(! self::is($actor) && self::permissionsInUse([$permission->id]) !== [], 403, self::PERMISSION_IN_USE);
    }

    /**
     * Which of these roles somebody has.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public static function rolesInUse(array $ids): array
    {
        $role = app(PermissionRegistrar::class)->pivotRole;

        return DB::table(config('permission.table_names.model_has_roles'))->whereIn($role, $ids)->distinct()->pluck($role)
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Which of these permissions a role or an account has.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public static function permissionsInUse(array $ids): array
    {
        $permission = app(PermissionRegistrar::class)->pivotPermission;

        return DB::table(config('permission.table_names.role_has_permissions'))->whereIn($permission, $ids)->pluck($permission)
            ->merge(DB::table(config('permission.table_names.model_has_permissions'))->whereIn($permission, $ids)->pluck($permission))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * For a Discord mapping about to be added, changed or deleted: it hands its role to whoever holds the Discord role it names.
     *
     * @param  string|null  $from  The role it maps now.
     * @param  string|null  $to  The role it is to map afterwards, none if it is being deleted.
     */
    public static function authorizeMapping(?User $actor, ?string $from, ?string $to): void
    {
        if (self::is($actor)) {
            return;
        }

        $names = array_values(array_filter([$from, $to], is_string(...)));

        abort_if(self::named(...$names), 403, self::MAPPING);
        // A role that is not there yet could be made later, with anything on it.
        abort_if($to !== null && Role::query()->where('name', $to)->doesntExist(), 403, self::NO_SUCH_ROLE);

        $roles = Role::query()->with('permissions')->whereIn('name', $names)->get();

        abort_unless($roles->every(fn (Role $role): bool => self::holdsAllOf($actor, $role)), 403, self::NOT_HELD);
    }

    /**
     * For a mapping about to start taking its role from whoever lacks the Discord role: made to sync from Discord, or one that does
     * pointed at another role or Discord role.
     */
    public static function authorizeSyncFromDiscord(?User $actor, string $role): void
    {
        abort_if(! self::is($actor) && self::rolesInUse(Role::query()->where('name', $role)->pluck('id')->all()) !== [], 403, self::INBOUND);
    }

    /**
     * For a provider account staff are about to link by hand. Whoever holds a Discord account is given the roles that are synced from
     * Discord, so linking one gives them.
     */
    public static function authorizeProviderLink(?User $actor, string $provider): void
    {
        if (self::is($actor) || $provider !== config('discord.social_provider')) {
            return;
        }

        $synced = DiscordRoleMapping::query()->where('sync_from_discord', true)->pluck('spatie_role_name')->all();
        $roles = Role::query()->with('permissions')->whereIn('name', $synced)->get();

        abort_unless($roles->every(fn (Role $role): bool => self::mayGive($actor, $role)), 403, self::PROVIDER);
    }

    /** Whether somebody who is not an administrator may give this role. */
    private static function mayGive(?User $actor, Role $role): bool
    {
        return ! self::isStaffRole($role->name) && self::holdsAllOf($actor, $role);
    }

    private static function holdsAllOf(?User $actor, Role $role): bool
    {
        return self::holds($actor, $role->permissions->pluck('name')->all());
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private static function holds(?User $actor, array $permissions): bool
    {
        return $actor !== null && array_diff($permissions, self::held($actor)) === [];
    }

    /**
     * @return array<int, string>
     */
    private static function held(?User $actor): array
    {
        return $actor?->getAllPermissions()->pluck('name')->values()->all() ?? [];
    }
}
