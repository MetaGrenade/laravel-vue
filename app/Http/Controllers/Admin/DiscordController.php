<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class DiscordController extends Controller
{
    public function roleMappings(Request $request): Response
    {
        abort_unless($request->user()?->can('system.acp.view'), 403);

        return Inertia::render('acp/DiscordRoleMappings', [
            'spatieRoles' => Role::query()->orderBy('name')->pluck('name')->values()->all(),
        ]);
    }

    public function sync(Request $request): Response
    {
        abort_unless($request->user()?->can('system.acp.view'), 403);

        return Inertia::render('acp/DiscordSync');
    }
}
