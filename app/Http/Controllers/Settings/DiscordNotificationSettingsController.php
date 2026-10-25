<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DiscordNotificationSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/DiscordNotifications');
    }
}
