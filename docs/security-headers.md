# Security headers

Every answer the application gives carries a set of headers from `App\Http\Middleware\SecurityHeaders`. The values live in `config/security.php`.

> **Status:** checked on every CI run (SQLite, MySQL 8 and PostgreSQL 16).

## What every answer sends

- `X-Content-Type-Options: nosniff`, so a browser does not guess a file type.
- `X-Frame-Options: SAMEORIGIN` and a content security policy `frame-ancestors 'self'`, so another site cannot put a page of this one in a frame.
- `Referrer-Policy: strict-origin-when-cross-origin`.
- `Permissions-Policy` with the camera, microphone, location and browsing topics switched off.
- `Cross-Origin-Opener-Policy: same-origin-allow-popups`, so a payment window can still open.
- `Strict-Transport-Security` only when the request is HTTPS and `SECURITY_HSTS` is on (it is on in production). `SECURITY_HSTS_SUBDOMAINS` adds `includeSubDomains`. It is left off until every subdomain is served over HTTPS.

The middleware is the outermost global one (`bootstrap/app.php`), so an answer that never reaches a route's own middleware has them too: an address no route has, a record that is not there, a visitor sent to sign in, a form whose token has run out, a request over its limit, the maintenance page, every answer of the API and Laravel's health address.

What the application does not send has them only if the web server adds them: the files in `public/` (the built scripts and styles, and uploads served through `public/storage`), and the page `php artisan down --render` writes beforehand, which is shown without starting the application.

## The content security policy

A page also gets a content security policy with a fresh nonce on each response. Inline scripts in the page carry that nonce. `object-src` is `none` and `base-uri` is `'self'`.

It goes on an HTML answer from a route in the `web` or `api` group, and on the error page of an address no route has. An answer that is not HTML, such as the sitemap, the web manifest, `security.txt` or the API's JSON, does not get it. Nor does a route outside those two groups. Those are Laravel's own and Cashier's payment page, and two of them are pages the policy would break:

| Address | What the policy would refuse |
|---|---|
| `/up` | Laravel's health page takes its styles from other hosts. |
| `/billing/payment/{id}` | Cashier's page, where a customer confirms a payment with their bank: its script is written into the page with no nonce. |

`SecurityHeadersTest` lists the routes outside the two groups, so a new one is noticed. A page of your own belongs in the `web` group.

`CSP_REPORT_ONLY=true` sends the policy as `Content-Security-Policy-Report-Only` instead, so a change can be tried without blocking the page. `CSP_ENABLED=false` turns the policy off. The other headers stay.

## Inline styles

`style-src` is `'self'` and `'unsafe-inline'`, and nothing else. Vue sets styles on elements, and those have no nonce. Removing the inline exception would break the pages that do that. Scripts do not get the same exception: a script needs the nonce. The check in CI compares that whole source list, so a later host or a wildcard fails the check. The Vite dev server is added only while it is running.

## The API reference page

`/api/docs` has the policy every page has: it names no other host and needs no exception. Swagger UI is part of the build (`resources/js/api-docs.ts`, the `swagger-ui-dist` package at an exact version), so its script and styles come from the site, its script tag carries the nonce, and the page has no script written into it. The few styles written into the page are covered by `'unsafe-inline'`. Swagger UI's online validator is switched off (`validatorUrl: null`): left alone it asks `validator.swagger.io` for a badge and tells it the address of the site.

`tests/Feature/ApiDocsPageTest.php` checks that every script has the nonce, that everything the page loads is the site's own and that the policy is the home page's. The [browser suite](e2e-tests.md) checks that the page is drawn with nothing refused. Neither covers the validator, which Swagger UI never asks about a site on `localhost`.

## Adding a third-party host

A script, frame or connection to another host has to be named in `config/security.php` (or it is added for the Vite dev server, the broadcasting host, and Tebex's checkout frame when that checkout is embedded). Set `CSP_REPORT_ONLY=true` first and read the browser console.

## Testing

`tests/Feature/SecurityHeadersTest.php` loads the home page and checks each header, the nonce on the scripts, and the frame and base rules. The style rule is compared source for source with `'self'` and `'unsafe-inline'`. It checks that HTTPS sends strict transport security and HTTP does not, including the subdomain form. It checks the sitemap and the web manifest keep the other headers and omit the policy. It asks for the answers no route's own middleware sees (an unknown address, a method an address does not take, a missing record, a visitor sent to sign in, a form without its token, a request over its limit, maintenance, a form too big to read) and expects the headers and the policy on each, and for the API, the health page and Cashier's payment page, which get the headers alone. It lists the routes outside the `web` and `api` groups. `.github/workflows/tests.yml` runs that file with the rest of the suite on SQLite, MySQL 8 and PostgreSQL 16.
