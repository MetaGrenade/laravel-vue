<?php

namespace App\Support\Security;

use App\Models\User;
use App\Support\Routing\ZiggyRouteGroup;
use Spatie\Permission\Models\Permission;

/**
 * Whose account is a staff account, for the rules that leave one to an administrator (docs/acp-permissions.md): somebody with the
 * admin, editor or moderator role, or with any permission of the admin area, through a role or on its own. A permission opens its pages
 * without one of those roles too (billing does), so an account holding one is worth taking over.
 */
final class Staff
{
    /** What a permission of the admin area has in its name (`users.acp.edit`). */
    private const ADMIN_AREA = '.acp.';

    /**
     * Loads the account's roles and permissions if they are not loaded: load them for a whole list before asking about each.
     */
    public static function is(User $account): bool
    {
        $account->loadMissing(['roles.permissions', 'permissions']);

        return $account->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES)
            || $account->getAllPermissions()->contains(fn (Permission $permission): bool => str_contains($permission->name, self::ADMIN_AREA));
    }
}
