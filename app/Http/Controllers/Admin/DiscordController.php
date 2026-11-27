<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Security\Administrators;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class DiscordController extends Controller
{
    public function roleMappings(Request $request): Response
    {
        abort_unless($request->user()?->can('system.acp.view'), 403);

        // The administrator role is for an administrator to map (RestrictDiscordAdminChanges refuses anybody else).
        $locked = Administrators::is($request->user()) ? [] : [Administrators::ROLE];

        return Inertia::render('acp/DiscordRoleMappings', [
            'spatieRoles' => Role::query()->whereNotIn('name', $locked)->orderBy('name')->pluck('name')->values()->all(),
            'lockedRoles' => $locked,
            // Without a server and a bot token there are no Discord roles to list; the page does not ask for them then. (The companion
            // package answers 422 "DISCORD_GUILD_ID is not configured" to that request, and the browser writes every such answer to its console.)
            'discordConfigured' => (string) config('discord.guild_id') !== '' && (string) config('discord.bot_token') !== '',
        ]);
    }

    public function sync(Request $request): Response
    {
        abort_unless($request->user()?->can('system.acp.view'), 403);

        return Inertia::render('acp/DiscordSync');
    }
}
