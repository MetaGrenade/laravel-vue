<?php

namespace App\Support\Routing;

use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\AuditAdminActions;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventBannedUser;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\UpdateLastActivity;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Routes for documents that are the same for everyone: robots.txt, the sitemap, the feeds (docs/http-caching.md).
 *
 * A page is built for the person who asked: their session, their language, their theme, the token a form sends back. A document is
 * not, so the routes declared here start no session (no row written for every crawler and feed reader, no cookie sent) and are
 * marked for any cache between the server and the reader to keep for a while, with an ETag so a reader can ask whether it changed.
 *
 *     PublicDocuments::cachedFor(300)->get('/blogs/feed', [BlogController::class, 'feed'])->name('blogs.feed');
 *
 * A response that is not a success (a section switched off, maintenance) is never marked for keeping.
 */
final class PublicDocuments
{
    /**
     * What the web group (bootstrap/app.php) runs that needs a session or a signed-in member, or that makes the answer depend on who
     * is asking: nothing here can run on a request that has no session, and a document must not change with the language or theme.
     * `PublicDocumentsTest` fails when the web group gains a middleware that is in neither this list nor the short list it keeps.
     *
     * @var list<class-string>
     */
    public const WITHOUT = [
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        PreventBannedUser::class,
        HandleAppearance::class,
        SetLocale::class,
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
        UpdateLastActivity::class,
        AuditAdminActions::class,
        ApplyImpersonation::class,
    ];

    /**
     * Declare the next route as a document that a cache may keep for this many seconds.
     */
    public static function cachedFor(int $seconds): RouteRegistrar
    {
        return Route::withoutMiddleware(self::WITHOUT)
            ->middleware('cache.headers:public;max_age='.$seconds.';etag');
    }
}
