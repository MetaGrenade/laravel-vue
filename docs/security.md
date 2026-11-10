# How the site protects itself

What MetaForger does about security without being asked, and where each part is written up. This is the map; the pages it links to are the detail. For a list of what to do on your own server, see the hardening notes in [SECURITY.md](../SECURITY.md); to report a problem, see [Reporting a vulnerability](security-contact.md).

> **Status:** each item below is built and has tests. None of it replaces a review of your own changes, a hardened server, or updates.

## What people send us

- **User content is cleaned when it is saved.** Forum threads and replies and blog bodies pass through `App\Support\Security\HtmlSanitizer` (Symfony's HTML sanitiser) by way of the `SanitizedHtml` cast, so the text the pages show with `v-html` cannot carry a script. The forum keeps only the markup its editor can produce; the blog allows the wider set of safe elements. After upgrading from a version that stored text some other way, run `php artisan content:sanitize` once ([Upgrading](upgrading.md)).
- **Pictures are decoded and written again.** A product picture or a cover that is uploaded is re-encoded to WebP, so whatever else was in the file does not survive ([Pictures](images.md)).
- **Files that are not pictures stay private.** Support attachments, forum attachments and digital downloads live on a disk the web server does not serve, and are opened only through a route that checks who is asking (downloads as signed, short-lived, download-limited links). A disk setting that would publish them is refused ([Private uploads](private-uploads.md)).
- **Money is worked out on the server.** The prices, shipping and tax of an order are always calculated again from the products, never read back from the cart or the form ([The shop](commerce.md)).

## Who may do what

- **Roles and permissions** (Spatie) guard the admin area and the moderation routes, and policies guard the records that belong to somebody: access goes through the owner, not through an id in the request ([Admin permissions](acp-permissions.md), [Ownership](ownership.md), [Who may see an order, a ticket, or a product](record-policies.md)).
- **The admin area is not advertised.** Ziggy publishes the routes in `config/ziggy.php` by group: guests and members never receive the admin route map, and the integration routes (Stripe, Cashier, Sanctum, storage) go to nobody.
- **Everything staff change is written down.** An append-only [audit log](audit-log.md) records who did what to what, and a staff member who looks at the site as a customer does so read-only and leaves a trail ([Read-only impersonation](impersonation.md)).
- **Two-factor sign-in** with an authenticator app and recovery codes, a limit on guesses, and the password asked again before two-factor is turned on or off, a new set of codes is made or the stored ones are shown again, or the email address is changed (the old address is told, [Two-factor authentication](two-factor.md#changing-the-email-address)). The codes themselves are not sent with every page load, only when they are made or asked for ([Seeing the recovery codes](two-factor.md#seeing-the-recovery-codes)). A member who is locked out is let back in by staff with their own permission, with a reason in the audit log and an email to the member ([When a member is locked out](two-factor.md#when-a-member-is-locked-out)).
- **Making or rotating an API key asks for the password**, because a key goes on working after the session that made it ends ([API keys](api-keys.md#asking-for-the-password)).
- **Other browsers are signed out when the password changes**, on any session driver, and a **Sign out other browsers** button does the same without changing it. Where sessions are in the database, a list of the browsers that are open comes with a button that ends one ([Browser sessions](two-factor.md#browser-sessions)).
- **Social sign-in trusts verified addresses only.** An address from Google or Discord is used to recognise an account, or to mark a new one verified, only when the provider says it checked it ([Social login](social-login.md)).
- **API keys** carry the scopes their owner ticked and an expiry, are refused with a 403 that names the missing scope, and are throttled each on its own ([API keys](api-keys.md), [HTTP API](api.md#limits)).

## What the server sends and refuses

- **Security headers and a content security policy** with a new nonce on every page: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS over HTTPS in production, and the policy. `config/security.php` tunes them; `CSP_REPORT_ONLY=true` tries a change without blocking anything. Adding a script, style or API host means adding its origin to the matching directive ([Security headers](security-headers.md)).
- **Rate limits.** Named limiters (`AppServiceProvider::configureRateLimiting()`) cover registration, password resets and confirmation, the two-factor challenge, posting, reports, search, billing, discount codes, downloads and each part of the API, and a test fails when a route names a limiter that does not exist or counts with bare numbers (`throttle:20,1`), which would share one count across the whole application ([HTTP API](api.md#limits)).
- **Production defaults** (`APP_ENV=production`): passwords of 12 or more characters with upper and lower case letters and numbers, checked against known breaches; commands that destroy a database (such as `migrate:fresh` and `db:wipe`) refuse to run. `APP_FORCE_HTTPS=true` makes the site write `https` addresses behind a proxy that ends TLS, and `SESSION_SECURE_COOKIE=true` keeps the session cookie off plain HTTP ([Deploying](deployment.md#behind-a-proxy-or-a-load-balancer)).
- **Stored and cached data is read back carefully.** Sessions use Laravel 13's JSON serialisation, and the cache turns text back into objects only for the classes listed under `serializable_classes` in `config/cache.php`, so a leaked `APP_KEY` does not become code execution through a forged payload.
- **Webhooks are checked, and the shop's are counted once.** Stripe's events are checked against the signing secret, and an order event that Stripe sends again is not processed twice ([Payments](payments.md), [Webhooks](webhooks.md)).

## What keeps the code honest

A secrets scan reads every commit for a committed key ([Secrets scan](secrets-scan.md)); `composer audit` and `npm audit` run on every change and each week; Dependabot opens a pull request for Composer, npm and GitHub Actions; the licences of everything shipped are checked against a policy ([Licences](licences.md)); and the suite checks the headers, the limits and the permissions of the pages it opens ([the checks](../CONTRIBUTING.md#checks)).
