<?php

use App\Http\Middleware\EnsureEmailIsVerifiedIfRequired;
use App\Http\Middleware\EnsureSiteIsAvailable;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogTokenActivity;
use App\Http\Middleware\PreventBannedUser;
use App\Http\Middleware\UpdateLastActivity;
use App\Jobs\MonitorSupportTicketSlas;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance']);

        $middleware->api(
            prepend: [
                EnsureFrontendRequestsAreStateful::class,
            ],
            append: [
                PreventBannedUser::class,
            ]
        );

        $middleware->web(
            prepend: [
                PreventBannedUser::class,
            ],
            append: [
                EnsureSiteIsAvailable::class,
                HandleAppearance::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
                UpdateLastActivity::class,
            ]
        );

        $middleware->alias([
            'verified' => EnsureEmailIsVerifiedIfRequired::class,
            'token.activity' => LogTokenActivity::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->job(new MonitorSupportTicketSlas())->everyFifteenMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
