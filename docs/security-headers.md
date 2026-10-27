# Security headers

Every page sent by the web middleware carries a set of headers from `App\Http\Middleware\SecurityHeaders`. The values live in `config/security.php`.

> **Status:** checked on every CI run (SQLite, MySQL 8 and PostgreSQL 16).

## What a page sends

- `X-Content-Type-Options: nosniff`, so a browser does not guess a file type.
- `X-Frame-Options: SAMEORIGIN` and a content security policy `frame-ancestors 'self'`, so another site cannot put a page of this one in a frame.
- `Referrer-Policy: strict-origin-when-cross-origin`.
- `Permissions-Policy` with the camera, microphone, location and browsing topics switched off.
- `Cross-Origin-Opener-Policy: same-origin-allow-popups`, so a payment window can still open.
- `Strict-Transport-Security` only when the request is HTTPS and `SECURITY_HSTS` is on (it is on in production). `SECURITY_HSTS_SUBDOMAINS` adds `includeSubDomains`. It is left off until every subdomain is served over HTTPS.
- A content security policy with a fresh nonce on each response. Inline scripts in the page carry that nonce. `object-src` is `none` and `base-uri` is `'self'`.

A response that is not HTML, such as the sitemap or the web manifest, still gets the other headers. It does not get the content security policy.

`CSP_REPORT_ONLY=true` sends the policy as `Content-Security-Policy-Report-Only` instead, so a change can be tried without blocking the page. `CSP_ENABLED=false` turns the policy off. The other headers stay.

## Inline styles

`style-src` includes `'unsafe-inline'`. Vue sets styles on elements, and those have no nonce. Removing it would break the pages that do that. Scripts do not get the same exception: a script needs the nonce. The check in CI includes the style rule, so a later edit cannot widen it without the check failing.

## Adding a third-party host

A script, frame or connection to another host has to be named in `config/security.php` (or it is added for the Vite dev server, the broadcasting host, and Tebex's checkout frame when that checkout is embedded). Set `CSP_REPORT_ONLY=true` first and read the browser console.

## Testing

`tests/Feature/SecurityHeadersTest.php` loads the home page and checks each header, the nonce on the scripts, and the style, frame and base rules. It checks that HTTPS sends strict transport security and HTTP does not, including the subdomain form. It checks the sitemap and the web manifest keep the other headers and omit the policy. `.github/workflows/tests.yml` runs that file with the rest of the suite on SQLite, MySQL 8 and PostgreSQL 16.
