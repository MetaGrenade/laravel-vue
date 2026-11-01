<?php

use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\AuditAdminActions;
use App\Http\Middleware\EnsureEmailIsVerifiedIfRequired;
use App\Http\Middleware\EnsureEntitled;
use App\Http\Middleware\EnsureSiteIsAvailable;
use App\Http\Middleware\EnsureTokenScope;
use App\Http\Middleware\EnsureWebsiteSectionIsEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogTokenActivity;
use App\Http\Middleware\MeterUsage;
use App\Http\Middleware\PreventBannedUser;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleTokenUsage;
use App\Http\Middleware\UpdateLastActivity;
use App\Jobs\AggregateSearchQueryStats;
use App\Jobs\ExpirePendingOrders;
use App\Jobs\LiftRunOutBans;
use App\Jobs\MonitorSupportTicketSlas;
use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneSearchQueryLogs;
use App\Jobs\PruneUsageEvents;
use App\Jobs\SendEmailDigests;
use App\Jobs\SyncTebexCatalogue;
use App\Payments\Tebex\TebexCatalogue;
use App\Support\Errors\ErrorPages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
    ->withBroadcasting(__DIR__.'/../routes/channels.php')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance']);

        $middleware->api(
            prepend: [
                EnsureFrontendRequestsAreStateful::class,
            ],
            append: [
                PreventBannedUser::class,
                ApplyImpersonation::class,
            ]
        );

        // PreventBannedUser must run after StartSession so it can resolve the
        // session-authenticated user and invalidate their session.
        $middleware->web(
            append: [
                SecurityHeaders::class,
                PreventBannedUser::class,
                EnsureSiteIsAvailable::class,
                HandleAppearance::class,
                SetLocale::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
                UpdateLastActivity::class,
                AuditAdminActions::class,
                ApplyImpersonation::class,
            ]
        );

        $middleware->alias([
            'verified' => EnsureEmailIsVerifiedIfRequired::class,
            'token.throttle' => ThrottleTokenUsage::class,
            'token.activity' => LogTokenActivity::class,
            'usage' => MeterUsage::class,
            'scope' => EnsureTokenScope::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'section.enabled' => EnsureWebsiteSectionIsEnabled::class,
            'entitled' => EnsureEntitled::class,
        ]);

        // The usage meter has to see a request before Laravel looks up what its address names (SubstituteBindings, which the api group
        // runs): a request for something that is not there is still a use, and an account at its limit is refused rather than told "not
        // found". It still comes after authentication, which is earlier in the list, since it needs to know whose use it is.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: MeterUsage::class);

        // The same goes for a key's scopes, so a key that may not read tickets is told so whether or not the ticket it asked for exists,
        // and cannot find out which ticket numbers are there. Added second, it lands right after the meter.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureTokenScope::class);

        // The audit log has to see an admin request that is refused by a route's `can:` (Authorize) or that names a record that is not
        // there (SubstituteBindings): both run before anything not in this list, so without this they would be turned away before the
        // log heard of them, and a refusal is exactly what it is for. It still comes after authentication, which is earlier in the list:
        // somebody who is not signed in is not recorded.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AuditAdminActions::class);

        // A read-only view as a customer makes the request the customer's, so everything after it (the sign-in check, throttles that
        // are per person, the route bindings, the pages) sees the customer: it goes right before authentication, which is the first
        // thing that asks who is signed in. It is after the session starts, which it reads.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ApplyImpersonation::class);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->job(new MonitorSupportTicketSlas)->everyFifteenMinutes();
        $schedule->job(new AggregateSearchQueryStats)->dailyAt('00:30');
        $schedule->job(new PruneSearchQueryLogs)->dailyAt('01:00');
        $schedule->job(new PruneUsageEvents)->dailyAt('01:30');
        $schedule->job(new PruneAuditLogs)->dailyAt('02:00');
        // Community email that a member asked to receive once a day, instead of once per update.
        $schedule->job(new SendEmailDigests)->dailyAt('08:00');
        // A ban with an end date lifts itself when the member next tries to sign in or make a request; this is for the lists of members.
        $schedule->job(new LiftRunOutBans)->everyMinute();
        $schedule->job(new ExpirePendingOrders)->everyFiveMinutes();
        // Looked at every five minutes; runs when the copy of the Tebex catalogue is older than the configured interval.
        $schedule->job(new SyncTebexCatalogue)->everyFiveMinutes()->when(fn () => app(TebexCatalogue::class)->isDue());
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A visit inside the app that is refused gets the Error page, or a notice on the page it was on (docs/error-pages.md).
        $exceptions->respond(ErrorPages::respond(...));
    })->create();
