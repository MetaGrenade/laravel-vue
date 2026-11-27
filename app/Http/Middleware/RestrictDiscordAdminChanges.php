<?php

namespace App\Http\Middleware;

use App\Support\Security\Administrators;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MetaForge\Discord\Models\DiscordRoleMapping;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Discord staff routes belong to the companion package, which asks only for what `discord.admin_middleware` names, for all of them
 * alike. That list opens them with `system.acp.view`, which is for looking. This adds the rest: changing anything needs
 * `system.acp.edit`, and a mapping of the administrator role, which gives that role to whoever holds the Discord role it names, is for
 * an administrator to change (docs/discord-companion.md).
 */
class RestrictDiscordAdminChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        Gate::authorize('system.acp.edit');

        // The role of the mapping the address names, and the one the request asks for.
        $mapping = $request->route('roleMapping');

        Administrators::authorizeMapping($request->user(), ...array_values(array_filter([
            $mapping instanceof DiscordRoleMapping ? $mapping->getAttribute('spatie_role_name') : null,
            $request->input('spatie_role_name'),
        ], is_string(...))));

        return $next($request);
    }
}
