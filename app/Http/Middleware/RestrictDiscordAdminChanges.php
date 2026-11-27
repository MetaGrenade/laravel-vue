<?php

namespace App\Http\Middleware;

use App\Rules\NotShapedLikeAnId;
use App\Support\Security\Administrators;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use MetaForge\Discord\Models\DiscordRoleMapping;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Discord staff routes belong to the companion package, which asks only for what `discord.admin_middleware` names, for all of them
 * alike. That list opens them with `system.acp.view`, which is for looking. This adds the rest: changing anything needs
 * `system.acp.edit`, and a mapping, which gives its role to whoever holds the Discord role it names, can be changed only by somebody
 * who could give that role themselves, and made to take it away only while nobody has it (docs/discord-companion.md).
 */
class RestrictDiscordAdminChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        Gate::authorize('system.acp.edit');

        $mapping = $request->route('roleMapping');
        $mapping = $mapping instanceof DiscordRoleMapping ? $mapping : null;

        // The role of the mapping the address names, and the one it is to name afterwards: none for one that is being deleted.
        $from = $mapping === null ? null : (string) $mapping->getAttribute('spatie_role_name');
        $asked = $request->input('spatie_role_name');
        $to = $request->isMethod('DELETE') ? null : (is_string($asked) ? $asked : $from);

        Administrators::authorizeMapping($request->user(), $from, $to);

        // The bot gives a mapping's role by its name, which must not be one Spatie takes for a number. Anybody's mapping.
        Validator::make(['spatie_role_name' => $to], ['spatie_role_name' => [new NotShapedLikeAnId]])->validate();

        if ($to !== null && $this->startsTaking($request, $mapping, $to)) {
            Administrators::authorizeSyncFromDiscord($request->user(), $to);
        }

        return $next($request);
    }

    /**
     * Whether the mapping this request asks for would take its role from accounts that the one there now does not.
     */
    private function startsTaking(Request $request, ?DiscordRoleMapping $mapping, string $role): bool
    {
        $was = $mapping !== null && (bool) $mapping->getAttribute('sync_from_discord');
        $will = $request->has('sync_from_discord') ? $request->boolean('sync_from_discord') : $was;

        if (! $will) {
            return false;
        }

        return ! $was
            || $role !== $mapping->getAttribute('spatie_role_name')
            || ($request->has('discord_role_id') && $request->input('discord_role_id') !== $mapping->getAttribute('discord_role_id'));
    }
}
