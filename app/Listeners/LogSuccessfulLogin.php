<?php

namespace App\Listeners;

use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\Login;

class LogSuccessfulLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        AuditLogger::log(
            'auth.login',
            'User logged in',
            [
                'remember' => (bool) $event->remember,
            ],
            $user,
            $user,
        );
    }
}

