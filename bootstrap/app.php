<?php

use App\Http\Middleware\EnsureEmailIsVerifiedIfRequired;
use App\Http\Middleware\EnsureSiteIsAvailable;
use App\Http\Middleware\EnsureWebsiteSectionIsEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogTokenActivity;
use App\Http\Middleware\PreventBannedUser;
use App\Http\Middleware\ThrottleTokenUsage;
use App\Http\Middleware\UpdateLastActivity;
use App\Jobs\AggregateSearchQueryStats;
use App\Jobs\MonitorSupportTicketSlas;
use App\Jobs\PruneSearchQueryLogs;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Broadcast;
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
    ->withBroadcasting(function () {
        Broadcast::routes(['middleware' => ['auth']]);

        require base_path('routes/channels.php');
    })
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
            'token.throttle' => ThrottleTokenUsage::class,
            'token.activity' => LogTokenActivity::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'section.enabled' => EnsureWebsiteSectionIsEnabled::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->job(new MonitorSupportTicketSlas())->everyFifteenMinutes();
        $schedule->job(new AggregateSearchQueryStats())->dailyAt('00:30');
        $schedule->job(new PruneSearchQueryLogs())->dailyAt('01:00');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
