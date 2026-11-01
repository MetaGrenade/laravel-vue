# Error pages

What somebody sees when the server refuses what they asked for: a page that is not there, one their account cannot open, a server that failed, a request that came too often, a session that ended. Roadmap section 5.1 asked for branded pages; this is how they work.

There are two kinds of page, and which one a request gets depends on how it was made.

| How it was asked | What comes back |
|---|---|
| A full page load (typing an address, a link from another site, a reload) | The standalone page, `resources/views/errors/minimal.blade.php`. No JavaScript, no layout, the indigo and gold plate. |
| A visit inside the app, asking for a page (a link, the back button, `router.visit`) | The `Error` page (`resources/js/pages/Error.vue`), in the app's own layout, with the status in the response. |
| A visit inside the app, doing something (a form, a button) | The page the person was on, with a notice (a toast). What they typed stays where it was. |
| A request for JSON, an API client | Laravel's JSON error, as before. |

The Inertia client cannot show the standalone page as a page: when a visit is answered with a page that is not an Inertia response it puts it in a dialog, with an iframe, over the page the person was on. That is what an error looked like before; it is what you still get for a status this app has no words for (below).

## Which statuses

`App\Support\Errors\ErrorPages::STATUSES`: 401, 402, 403, 404, 419, 429, 500 and 503. They are the ones that have words in `lang/en/errors.php`, and a test fails when a status is added to the list without them. Anything else (405, 413, 418 and so on) is left as it was.

A 500 while `APP_DEBUG` is on is also left as it was: it is Laravel's own page with the exception on it, which is what a developer came for. With debugging off, the page says only that something went wrong. What went wrong is in the log, never on the page.

## A visit that asks for a page

The answer is an Inertia response for the `Error` page with the real status (404, 500...), so the address bar shows the address that failed, the back button goes back, and a reload loads the standalone page for the same status.

The page is built through `HandleInertiaRequests`, whether or not the request got as far as that middleware before it failed. Route model binding (`SubstituteBindings`) and the session, CSRF and ban checks run before it, so a 404 for a bound record would otherwise have no shared data: no signed-in member, no translations, no routes, and the layout would break. (A record that a controller looks up itself fails after the middleware, and the data is there already; running it again costs a few queries on a page that is an error.)

It shows:

- the status, and one line about the page (`errors.hints.<status>`);
- under it, for a 403, the message that was given to `abort(403, '...')`: a ban's reason, "Your account does not include this", why a review cannot be written. A message from a policy and one on any other status are not shown. Only a message somebody wrote for the person who is refused is meant to be read;
- under it, for a 429 or 503, when to try again, from the `Retry-After` header ("Try again in 45 seconds", "Try again in 3 minutes");
- Try again (a reload) when trying again may work (429, 500, 503), Go back, and Go to the homepage.

`Retry-After` and the `X-RateLimit-*` headers are kept on the response.

### When the page cannot be given

The visit is answered with `Inertia::location()`: the browser loads the address itself, and gets the standalone page. That happens when

- no session was started, so the shared data does not exist: an address no route has (the web middleware never ran for it) or a request that failed before it. This is not reported as a failure;
- building the page failed (the database is down, a shared prop throws). This is reported, and the visit falls back instead of becoming a second error on top of the first;
- the person's tab is from before a deploy (its asset version is not the server's). The same happens for every visit then, and is what the Inertia middleware always does.

The cost is a second request to the failing address, from people who clicked a link, never from crawlers (they do not send the Inertia header).

Maintenance mode answers a visit the same way: `EnsureSiteIsAvailable` returns a page rather than throwing, so it is told to load the address in full and the maintenance page is shown as a page, not as a dialog over the one before.

## A visit that does something

A form or a button that is refused must not replace the page with an error page: what somebody typed would be lost, and they would have to find their way back. The response is a redirect back to the page they were on (303, whatever the method, so the client asks for it again with GET rather than repeating a DELETE) with a flash message under `error`, which the app shows as a toast like every other notice (`resources/js/lib/flashToast.ts`).

What the notice says:

| Status | Notice |
|---|---|
| 403 with a message given to `abort()` | That message |
| 401, 402, 403, 404, 419, 429, 500, 503 | `errors.actions.<status>`, for example "That is no longer there. It may have been removed." |

If the page they are sent back to fails too (the thread was deleted, so its page is a 404), that visit is a visit that asks for a page and shows the Error page, with the notice still in the shared `flash`.

A form with mistakes is not an error: validation answers with a redirect and the errors, as before. And when there is no session (an action at an address no route has) there is nowhere to leave a notice, so the answer is left as it was.

## Adding a status, or changing the words

1. Add the status to `ErrorPages::STATUSES`.
2. Add `errors.hints.<status>` (the page) and `errors.actions.<status>` (the notice) to every `lang/<locale>/errors.php`. `ErrorPagesTest` fails until both exist, and `php artisan lang:check` finds a locale that is missing one.

The words are Laravel translations, not part of the front-end groups in `config/i18n.php`, so they are not sent with every page: the `Error` page is handed the strings it shows. The standalone page reads the same `errors.hints.*` and `errors.home`.

## What is not covered

- A request that no route matches and is not a GET (a POST to a wrong address, a 405) is left as it was, so the Inertia client shows its dialog. Nothing in the app sends one.
- Statuses that proxies and web servers produce (502, 504, an nginx 413) never reach Laravel, so they are the server's own pages.
- The standalone page has the plate artwork; the `Error` page is the plain layout. If the two should match, restyle `Error.vue`.

## Tests

`tests/Feature/ErrorPagesTest.php`: the Error page for each case above on routes that fail on purpose and on the real ones (a missing blog post, a refused ACP page), the notice for each method, the debug exception, the fallbacks and the reporting, the standalone page and JSON staying as they were, and maintenance mode.
