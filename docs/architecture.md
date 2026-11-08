# How MetaForger is put together

A map of the parts and of how a request travels through them, for someone who has to run the site, read the code or change it. It does not repeat the pages that explain one part; every section says where that page is.

> **Status:** written from `bootstrap/app.php`, the route lists, `composer.json` and `package.json`, the other pages in this folder, and the code they describe. The path of an API request was followed by calling the API on a throwaway copy of the site ([api.md](api.md)). The CI jobs are the ones in `.github/workflows`. **Not run:** Octane, Horizon and a Docker image ([octane.md](octane.md), [redis.md](redis.md)).

## In one paragraph

One Laravel application on one database answers three kinds of caller: a **browser** (a controller renders a Vue page through Inertia), a **script** (the JSON API under `/api/v1`) and **other services** (Stripe and Tebex call webhooks). What is slow or has a time attached to it (every email, unpaid orders that expire, the daily digests, pruning old logs) is a **job on a queue**, run by a worker and started on time by the scheduler. The cache, the sessions, the queue and the search each have a **database default**, so a new site needs no other service, and a faster choice for production (Redis, Meilisearch) that is a setting and not a code change. Live updates use Pusher when its credentials are set.

## What it is built with

The versions are the constraints in `composer.json` and `package.json`.

| Part | What | Version |
|---|---|---|
| Language and framework | PHP, Laravel | ^8.4, ^13.0 |
| Pages | Inertia (server and `@inertiajs/vue3`), Vue, TypeScript, Vite, Tailwind CSS | ^3.0 and ^3.9, ^3.5, ~6.0, ^8.3, ^4.3 |
| Components and editor | Reka UI (the unstyled parts under `components/ui`), Tiptap (the rich-text editor), Unovis (charts), Lucide (icons) | see `package.json` |
| API keys | Laravel Sanctum | ^4.3 |
| Roles and permissions | spatie/laravel-permission | ^8.0 |
| Subscriptions and invoices | Laravel Cashier (Stripe), dompdf (the invoice PDFs) | ^16.0, ^3.1 |
| Search | Laravel Scout and the Meilisearch client | ^11.9, ^1.17 |
| Live updates | Pusher (server), Laravel Echo and pusher-js (browser) | ^7.3, ^2.6, ^8.6 |
| Named routes in the browser | Ziggy | ^2.6 |
| Safe rich text | symfony/html-sanitizer | ^8.1 |
| Discord | `metagrenade/metaforge-discord`, kept apart from this repository | `dev-main` ([discord-companion.md](discord-companion.md)) |
| Browser tests | Playwright and axe | see `package.json` |

## Three ways in

| Caller | Routes | Signs in with | What stands in front |
|---|---|---|---|
| A browser | `routes/web.php`, which also loads `admin.php` (everything under `acp/`), `settings.php` and `auth.php` | A session cookie | The `web` middleware group |
| A script | `routes/api.php`, under `/api` | A bearer key (Sanctum) | The `api` group, then the key's checks ([api.md](api.md)) |
| Another service | `POST /stripe/webhook`, `POST /webhooks/tebex` (in `web.php`, with the forgery check taken off: they are proved by what the sender attaches instead) | Nothing: a signature on the body (Tebex's are also checked against the sender's address) | [webhooks.md](webhooks.md) |

There is also the command line (`php artisan list` shows the site's own commands: `metaforger:install`, `metaforger:queue`, `metaforger:search:*`, `lang:check`, `api:docs`, `commerce:expire-orders`, `tebex:sync`, and a few that upgrade older data), and `/up`, Laravel's health address.

## A page request

Laravel does not run a route's middleware in the order they were listed: some are moved by a **priority list**, and `bootstrap/app.php` adds four to it. This is the order a page request goes through (`app('router')->gatherRouteMiddleware($route)` prints it for any route):

1. Laravel's own: cookies, the session, the forgery check.
2. **`ApplyImpersonation`**: when staff are viewing the site as a customer, the request becomes the customer's. It is placed right before the sign-in check, which is the first thing that asks who is there.
3. **`Authenticate`**, on a route that needs a signed-in person.
4. **`AuditAdminActions`**: writes a change made under `acp/` to the audit log ([audit-log.md](audit-log.md)). It comes before the record is looked up so that a change that is refused, or names a record that is not there, is recorded too.
5. **`SubstituteBindings`**: the record an address names is looked up.
6. The site's own, in the order `bootstrap/app.php` lists them:

| Middleware | What it does |
|---|---|
| `SecurityHeaders` | The security headers, and a Content Security Policy with a nonce ([security-headers.md](security-headers.md)). |
| `PreventBannedUser` | Ends the session of a member who has been banned. |
| `EnsureSiteIsAvailable` | Maintenance mode, as set in the admin area. |
| `HandleAppearance` | Light, dark or the system's, from the `appearance` cookie (the one cookie that is not encrypted). |
| `SetLocale` | The language of the request ([adding-a-language.md](adding-a-language.md#who-sees-which-language)). |
| `HandleInertiaRequests` | What every page is sent: who is signed in and what they may do, their entitlements, the language and its strings, flash messages, the bell's notifications, the sections that are switched on. Most of it is a closure, read only if the page asks. |
| `PrefetchAssetsForMembers` | Asks the browser to fetch the next pages' scripts only for signed-in members. |
| `AddLinkHeadersForPreloadedAssets` | Laravel's own preload headers. |
| `UpdateLastActivity` | Notes when a member was last seen. |

7. What the route itself lists after that: on an admin page `role:` and `can:`.

Because the record is looked up in step 5, before the language is set and before the page's shared data is built, a page for a record that is not there is made without them; `ErrorPages` hands the error page its own strings for that reason ([error-pages.md](error-pages.md)).

Then the route runs: its own middleware (`auth`, `can:`, `role:`, `verified`, `section.enabled:`, `entitled`, `translations:`), a form request that validates, the controller, and `Inertia::render('Area/Page', $props)`. The page is the Vue file `resources/js/pages/Area/Page.vue`. The **first visit** is a whole HTML page with the page's data in it; every visit after that is a request for JSON that swaps the page without a reload. With `INERTIA_SSR_ENABLED=true` the first visit's HTML is drawn by a Node process, as the deployment guide says ([deployment.md](deployment.md#server-side-rendering-optional)); it is off by default.

A refused or failed visit (`401`, `402`, `403`, `404`, `419`, `429`, `500` and `503`) is turned into the site's own error page, or a notice on the page the person was on, by `ErrorPages::respond` ([error-pages.md](error-pages.md)).

## An API request

The `api` group puts Sanctum's stateful check first (a call from the site's own pages, with a session, works as that person) and appends `PreventBannedUser` and `ApplyImpersonation`. For a route the order Laravel runs, printed for a ticket, a thread-moderation and the profile route, is:

1. `EnsureFrontendRequestsAreStateful`, then `ApplyImpersonation`.
2. **`auth:sanctum`**: is there a valid key.
3. The route's own **`throttle:`** limits.
4. **`usage:api-calls`**: the account's monthly allowance.
5. **`scope:`**: the key's scope.
6. **`SubstituteBindings`**: only now is the record the address names looked up.
7. `PreventBannedUser`, **`section.enabled:`**, **`token.throttle`** (the key's own hourly and daily limit), **`token.activity`** (the key's log) and, where a route needs one, `role:`.

So the allowance and the scope are decided before the record is looked up, which is why a key cannot find out what exists. The key's own limit, its log and the staff role are decided after it. The key's activity log therefore misses what is turned away before step 7: a missing record and a request over the route's rate limit were seen not to be written to it, and by the same order a scope refusal is not either. [api.md](api.md) is the reference and [api-keys.md](api-keys.md) the keys.

## Where the code is

| Where | What is in it |
|---|---|
| `routes/` | `web`, `auth`, `settings`, `admin`, `api`, `channels` (the private channels for live updates), `console` |
| `app/Http/Controllers/` | One folder for the admin area (`Admin`), the settings pages (`Settings`), the shop (`Ecommerce`), sign-in (`Auth`), the API (`Api/V1`) and the webhooks (`Webhooks`); the public pages (blog, forum, support, members, search) are at the top |
| `app/Http/Middleware/`, `Requests/`, `Resources/` | The steps above, the form requests, and the API's JSON resources |
| `app/Models/` | The tables, about a hundred models ([the families](#the-data)) |
| `app/Policies/` | The rules for one record each: orders, products, addresses, reviews, blog posts and comments, forum posts, support tickets and their attachments ([record-policies.md](record-policies.md)) |
| `app/Support/<Area>/` | The classes that do the work of an area, so a controller and a job can share it: `Commerce`, `Billing`, `Entitlements`, `Forum`, `Moderation`, `Search`, `Usage`, `Audit`, `Impersonation`, `Localization`, `Mail`, `Seo`, `Security` and the rest |
| `app/Payments/` | The payment provider contract, `PaymentManager`, the Stripe and Tebex providers and the webhook receiver |
| `app/Jobs`, `Notifications`, `Events`, `Listeners`, `Console/Commands` | Background work, what people are told, and the commands |
| `config/` | Every setting the site reads ([configuration.md](configuration.md)) |
| `database/` | Migrations, factories, seeders (including the three legal pages' starting text) |
| `lang/` | The words ([i18n.md](i18n.md)) |
| `resources/js/` | `pages` (one for each page a controller renders), `layouts`, `components`, `composables`, `lib`, `types`; `app.ts` starts the browser, `ssr.ts` the server rendering |
| `resources/views/` | Blade: the page that holds the app, the emails, the plain error page |
| `resources/css/app.css` | The colour and size tokens ([theming.md](theming.md)) |
| `tests/` | `Feature` and `Unit` (PHPUnit), `e2e` and `a11y` (Playwright, [e2e-tests.md](e2e-tests.md)) |
| `scripts/` | The checks that CI runs and that you can run: documentation links, bundle size, licences, install, time to first byte, Lighthouse |

## The data

One database, reached through Laravel's query builder and Eloquent, with the same migrations on every engine. CI runs the whole suite on SQLite and again on MySQL 8 and PostgreSQL 16 (`ci`, `ci-mysql`, `ci-pgsql`), so a migration that works on one engine only fails there. The tables fall into families:

| Family | Tables of |
|---|---|
| People | `User`, social sign-in accounts, badges, follows, reputation events, direct messages, notification settings |
| Blog | posts, revisions, categories, tags, comments, their reactions and reports |
| Forum | categories, boards, threads, posts and their revisions, prefixes, attachments, subscriptions, reports, ignores, the automod's rules |
| Support | tickets, messages and their attachments, categories, teams, assignment rules, response templates, the FAQ |
| Shop | products, variants, options, prices, pictures and files, carts, orders and their items, addresses, shipping zones and rates, tax rates, discount codes, gift cards, reviews, wishlists, stock, licence keys, download grants |
| Money | payments, refunds, `Entitlement`s and what plans and packages give, subscription plans, invoices, the provider's subscriptions, the webhook calls that were received |
| Tebex | the copy of the store's categories and packages |
| Records about the site | the audit log, usage events, API key logs, search queries, moderation actions, data exports and erasure requests, `SystemSetting` |

A record that is billable, or that a team could one day share, has an **owner** (`owner_type`, `owner_id`) as well as a `user_id` that says who acted ([ownership.md](ownership.md)). Every foreign key has an index, and the lists that are shown in an order have one for that order ([indexes.md](indexes.md)).

## Who may do what

Several questions are asked in different places, and each has one answer's home:

| The question | Answered by | Read |
|---|---|---|
| Who are you? | The session for pages; a Sanctum key for the API; Google, Discord or Steam sign-in as another way to get a session | [api-keys.md](api-keys.md) |
| Are you allowed in at all? | A ban (`PreventBannedUser`), maintenance mode, a verified address where the site asks (`verified`) | [moderation.md](moderation.md) |
| What kind of person are you? | Roles `admin`, `editor`, `moderator`, `user`; permissions named `<area>.acp.<action>` for the admin area | [acp-permissions.md](acp-permissions.md) |
| May you see or change this record? | A policy, asked through the record's owner | [record-policies.md](record-policies.md), [ownership.md](ownership.md) |
| Is this part of the site on? | The four section switches (blog, forum, support and the shop, which a route calls `commerce`): `section.enabled:` | [extending.md](extending.md#a-new-section-that-can-be-switched-off) |
| What did the plan or the purchase give you? | Entitlements: `$user->canUse('x')`, the `entitled` middleware | [entitlements.md](entitlements.md) |
| What may this key do? | Its scopes, which narrow and never add | [api-keys.md](api-keys.md#scopes) |
| Is staff acting as you? | Read-only impersonation: the request is yours, the pages are blocked from changing anything | [impersonation.md](impersonation.md) |

## Money

```
cart ──► order placed (pending, stock held) ──► the provider's page ──► webhook ──► order paid
```

The shop never talks to Stripe or Tebex directly. `PaymentManager` resolves a provider from `config/commerce.php` and the shop asks it what it can do (its capabilities) and not what it is. Every order records the provider it was placed with, so changing the store's provider never strands one in flight. The price is worked out again on the server when the order is placed, never read from the cart. The provider's webhook is stored once under the sender and the event's id, and a repeated delivery changes nothing; if the buyer comes back before the webhook, the return page asks the provider ([commerce.md](commerce.md#the-flow), [webhooks.md](webhooks.md)). Subscriptions are Stripe's, through Cashier, whichever provider the shop's orders use ([billing.md](billing.md)). What a purchase or a plan gives an account is computed again from what is true now after every change that matters, so it is safe to repeat ([entitlements.md](entitlements.md)). The whole path, from keys to going live, is [payments.md](payments.md).

## Background work

- **The queue.** Two queues carry the site's work, `default` and `mail`; a plain `queue:work` reads only one, so `php artisan metaforger:queue work` (or `listen`, with the same options) starts a worker on both ([redis.md](redis.md#the-queue)). The database is the queue until `QUEUE_CONNECTION` says otherwise.
- **The scheduler** is one cron line, and `bootstrap/app.php` lists what it puts on the queue: support SLA checks (every 15 minutes), the daily digests (08:00), the end of timed bans (every minute), expiring unpaid orders (every 5 minutes), refreshing the Tebex catalogue when it is due (checked every 5 minutes), and, at night, the search statistics and the pruning of the search log, usage events and audit log. A scheduler with no worker only fills the queue ([deployment.md](deployment.md#what-a-live-site-is-made-of)).
- **What people are told.** A notification goes to up to three places: a line in the bell (`database`), an email (`mail`, on the `mail` queue, in the recipient's language), and a browser alert. `SplitDelivery` writes the line in the site at once and queues the rest under the same id. Staff mirrors of support and moderation events go to Discord through the companion package ([notifications.md](notifications.md), [email.md](email.md), [i18n.md](i18n.md#emails)).
- **Live updates.** Echo listens on the private channels in `routes/channels.php` (one for each member, one for each forum thread), for the bell and for an open thread.

## Search

`SEARCH_DRIVER=database` (the default) searches with SQL. `meilisearch` searches an index kept by Scout, with a short circuit breaker that falls back to the database when Meilisearch cannot be reached, a health check (`metaforger:search:health`, also in the admin area), and commands to apply the index settings and to rebuild it. The global search has three groups, written out in `GlobalSearchService` ([search-index.md](search-index.md), [extending.md](extending.md#what-is-not-open-to-extension)).

## The front end

- **Pages** are Vue files in `resources/js/pages`, named in the controller. **Layouts** wrap them; **components** are shared pieces, with the plain controls under `components/ui`.
- **Links** are named routes: Ziggy sends the browser the route map for who is looking (anyone who is not staff does not get the `acp.*` names), and `route('name')` builds the address.
- **Words** come from `lang/` through `t()`; a page is sent only the groups it reads ([i18n.md](i18n.md)).
- **Look** is a set of colour tokens in CSS, light and dark, which Tailwind reads ([theming.md](theming.md)).
- **The editor** for posts and threads is Tiptap, loaded only on the pages that need it, and what it produces is cleaned by `HtmlSanitizer` whenever the record is written (the `SanitizedHtml` cast).
- **Built** by Vite into `public/build`. Each page has a size budget that CI holds ([bundle-budget.md](bundle-budget.md)).

## Settings live in three places

| Place | What | Changing it |
|---|---|---|
| `.env`, read by `config/*.php` | Keys, addresses, drivers, limits | Edit the file; clear the config cache. [configuration.md](configuration.md) lists them all. |
| **Admin → System settings**, the `system_settings` table | What a site owner changes while it runs: which sections are on, maintenance, mail look, security options | Saved at once. A request reads them all with one query, the first time one is asked for ([query-budgets.md](query-budgets.md)). |
| Tables | The shop's products, plans, entitlements, shipping, tax, discount codes; the forum's boards; the automod's rules | The admin area for each. |

## What cuts across everything

| Concern | Where | Read |
|---|---|---|
| Who did what in the admin area | The audit log, written by middleware from the address | [audit-log.md](audit-log.md) |
| What an account has used | Usage events and the monthly meter, for API calls | [usage.md](usage.md) |
| The security headers and the nonce | `SecurityHeaders`, `config/security.php` | [security-headers.md](security-headers.md) |
| Pages that are the same for everyone | `PublicDocuments`: no session, caching headers | [http-caching.md](http-caching.md) |
| Search engines | `Seo`, per request; the sitemap and `robots.txt` | [configuration.md](configuration.md#search-engines-and-social-cards) |
| Query counts | Lazy loading is forbidden in tests and each page has a ceiling | [query-budgets.md](query-budgets.md) |
| Long-lived workers | State that a process keeps is listed and tested | [octane.md](octane.md) |
| Rich text | Cleaned when it is written, by the `SanitizedHtml` cast and `app/Support/Security/HtmlSanitizer.php` (two policies, `forum` and `article`); `php artisan content:sanitize --dry-run` reports what an older record would change | The code; no page of its own yet |

## How it is kept true

Everything below runs in CI (`.github/workflows`):

| Workflow | Checks |
|---|---|
| `linter` | Pint, Prettier, ESLint, `vue-tsc`, Larastan (PHPStan), `lang:check`, the links in `docs/` |
| `tests` | The suite on SQLite (`ci`), MySQL 8 (`ci-mysql`) and PostgreSQL 16 (`ci-pgsql`); the accessibility audit (`a11y`); the browser smoke suite (`e2e`); Lighthouse (a report, not a gate); the search tests against Meilisearch (`search`); the cache, queue and sessions on Redis (`redis`); a clean install with `metaforger:install` (`install`); the size of the JavaScript (`bundle`) |
| `security` | A scan for committed secrets, dependency advisories (Composer and npm), and the licence policy |
| `stability` | Once a week, the suite repeated in random order |

The guards that fail for a specific mistake (a missing language key, a foreign key with no index, a route with no scope, a setting with no row in the reference, a link that goes nowhere) are listed in [extending.md](extending.md#the-tests-that-tell-you-what-you-forgot).

## What you provide, and what is optional

| For | You provide |
|---|---|
| Always | A web server with PHP 8.4, a database, a queue worker and one cron line ([deployment.md](deployment.md)) |
| For the shop | Stripe keys and its webhook, or a Tebex store and its webhook ([payments.md](payments.md)) |
| For email | A mail provider ([email.md](email.md)); the default `log` writes mail to a file |
| For live updates | Pusher credentials |
| For a bigger site | Redis for the cache, queue and sessions; Meilisearch for search |
| For Discord | The companion bot and its package ([discord-companion.md](discord-companion.md)) |
| For sign-in with a provider | Google, Discord or Steam credentials |

## What 1.1 changes

Teams: an account that several people share would own orders, addresses and entitlements. 1.0 already asks every such question through the owner, so the change is a table and a few resolvers and not a rewrite ([ownership.md](ownership.md#upgrading-to-teams-planned-for-11)). The first extra languages, and a visitor's own choice of language, are planned for 1.1 as well ([adding-a-language.md](adding-a-language.md)).
