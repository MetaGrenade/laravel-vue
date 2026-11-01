<?php

namespace App\Support\Errors;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * What a visit inside the app sees when the server refuses it (docs/error-pages.md).
 *
 * A link or button that fails used to answer with the standalone page (resources/views/errors), which the Inertia client cannot show as
 * a page: it puts it in a dialog over the page it was on, with an iframe. Now, for the statuses this app has words for:
 *
 *  - a visit that asks for a page (GET) is answered with the `Error` page, in the app's own layout, with the status in its response;
 *  - a visit that does something (a form, a button) goes back to the page it was on with a notice, so what somebody typed stays where
 *    it was and they are told what happened;
 *  - when the Error page cannot be given (no session was started, so the shared data the layout needs does not exist, or building it
 *    failed) the browser is sent to the address for a full page load, which gets the standalone page.
 *
 * A full page load, an API client and a request for JSON are not touched: they never send the Inertia header.
 */
final class ErrorPages
{
    /** The statuses that have words of their own in lang/<locale>/errors.php; any other answer is left as it is. */
    public const STATUSES = [401, 402, 403, 404, 419, 429, 500, 503];

    /** Statuses where trying again may work, so the page offers it. */
    private const RETRYABLE = [429, 500, 503];

    /** What the refusal says about when to ask again, which the answer in its place keeps. */
    private const KEPT_HEADERS = ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    /**
     * Registered with `$exceptions->respond()` in bootstrap/app.php.
     */
    public static function respond(Response $response, Throwable $e, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! $request->header(Header::INERTIA) || ! in_array($status, self::STATUSES, true)) {
            return $response;
        }

        // With debugging on, a server error is Laravel's own page with the exception on it, which is what a developer came for.
        if ($status === 500 && config('app.debug')) {
            return $response;
        }

        return $request->isMethod('GET')
            ? self::page($status, $response, $e, $request)
            : self::notice($status, $response, $e, $request);
    }

    /**
     * The Error page, or the address to load in full when it cannot be given.
     */
    private static function page(int $status, Response $response, Throwable $e, Request $request): Response
    {
        // A request the web middleware never saw (an address no route has, the site in maintenance) has no session, and the shared data
        // the layout needs is not there: a page without it would be a broken page, so let the browser load the address itself.
        if (! $request->hasSession()) {
            return Inertia::location($request->fullUrl());
        }

        try {
            // Through the Inertia middleware, so the shared data, the asset version and the root view are the ones every page has,
            // whether or not the request got as far as that middleware before it failed.
            $page = app(HandleInertiaRequests::class)->handle(
                $request,
                fn (Request $request) => Inertia::render('Error', self::props($status, $response, $e))->toResponse($request)->setStatusCode($status),
            );
        } catch (Throwable $failure) {
            // The page that tells somebody something went wrong must not be a second thing that went wrong.
            report($failure);

            return Inertia::location($request->fullUrl());
        }

        foreach (self::KEPT_HEADERS as $name) {
            if ($response->headers->has($name) && ! $page->headers->has($name)) {
                $page->headers->set($name, $response->headers->get($name));
            }
        }

        return $page;
    }

    /**
     * The page somebody was on, with what happened as a notice.
     */
    private static function notice(int $status, Response $response, Throwable $e, Request $request): Response
    {
        // Nowhere to leave a notice: the answer stays what it was.
        if (! $request->hasSession()) {
            return $response;
        }

        // 303 whatever the method: the client must ask for the page again with GET, not repeat a DELETE at it.
        return redirect()->back(303)->with('error', self::message($status, $e));
    }

    /**
     * @return array<string, mixed>
     */
    private static function props(int $status, Response $response, Throwable $e): array
    {
        return [
            'status' => $status,
            'hint' => __("errors.hints.{$status}"),
            'detail' => self::detail($status, $response, $e),
            'retryable' => in_array($status, self::RETRYABLE, true),
            'labels' => [
                'home' => __('errors.home'),
                'back' => __('errors.back'),
                'retry' => __('errors.retry'),
            ],
        ];
    }

    /**
     * What the notice says for a refused action: what was given to abort() when there was something to say, else the words for the status.
     */
    private static function message(int $status, Throwable $e): string
    {
        return self::reason($status, $e) ?? __("errors.actions.{$status}");
    }

    /**
     * A line under the one about the page: when to try again, or why an action was refused.
     */
    private static function detail(int $status, Response $response, Throwable $e): ?string
    {
        if (in_array($status, [429, 503], true)) {
            return self::retryIn((int) $response->headers->get('Retry-After'));
        }

        return self::reason($status, $e);
    }

    /**
     * The message somebody wrote for the person who is refused: abort(403, '...'), a ban's reason, what a plan does not include.
     * Only a 403 has one that is meant to be read; an exception that was turned into a 500 says what broke, and is kept to the log.
     */
    private static function reason(int $status, Throwable $e): ?string
    {
        if ($status !== 403 || ! $e instanceof HttpExceptionInterface || $e->getPrevious() !== null) {
            return null;
        }

        $message = trim($e->getMessage());

        return $message === '' ? null : $message;
    }

    private static function retryIn(int $seconds): ?string
    {
        return match (true) {
            $seconds < 1 => null,
            $seconds <= 120 => trans_choice('errors.retry_in_seconds', $seconds, ['seconds' => $seconds]),
            default => trans_choice('errors.retry_in_minutes', $minutes = (int) ceil($seconds / 60), ['minutes' => $minutes]),
        };
    }
}
