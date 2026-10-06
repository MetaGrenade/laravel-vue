<?php

namespace App\Support\Routing;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class ZiggyRouteGroup
{
    /**
     * Roles that can access the admin control panel (see routes/admin.php).
     */
    public const STAFF_ROLES = ['admin', 'editor', 'moderator'];

    /**
     * Request header the frontend uses to report which group's route map it holds.
     */
    public const HEADER = 'X-Ziggy-Group';

    /**
     * Resolve the Ziggy route group the given user is allowed to see.
     */
    public static function for(?Authenticatable $user): string
    {
        if ($user instanceof User && $user->hasAnyRole(self::STAFF_ROLES)) {
            return 'staff';
        }

        return 'public';
    }
}
