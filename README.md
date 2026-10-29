# MetaForge

A batteries-included boilerplate for building SaaS products, online shops and large community websites with Laravel and Vue. The project ships with a production-ready forum, blog, support center, a working shop and admin tooling so teams can focus on features rather than scaffolding. Inertia.js keeps the frontend and backend in sync, Tailwind CSS powers the design system, and first-class TypeScript support ensures maintainable UI code.

![Forum Page Example](https://i.imgur.com/gYNFkFl.png)

> **Status: pre-1.0.** The shop now works end to end (catalogue, cart, discount codes and gift cards, shipping and tax, Stripe checkout, orders, refunds, digital downloads and licence keys, wishlists and reviews). Tebex purchases are in as a preview (not yet checked against a real store), subscription plans give entitlements (a feature, a limit), and the rest of the SaaS work and search at scale are still ahead; the road to a stable 1.0.0 is laid out in [docs/ROADMAP-v1.0.0.md](docs/ROADMAP-v1.0.0.md). Notable changes are recorded in [CHANGELOG.md](CHANGELOG.md).

## Contents
- [Stack Highlights](#stack-highlights) · [Application Modules](#application-modules) · [Project Structure](#project-structure)
- [Prerequisites](#prerequisites) · [Quick Start](#quick-start) · [Setting up the shop](#setting-up-the-shop)
- [Realtime Broadcasting & Pusher Setup](#realtime-broadcasting--pusher-setup) · [Security](#security) · [SEO](#seo) · [OAuth & Social Login](#oauth--social-login)
- [Daily Development Workflow](#daily-development-workflow) · [Feature Notes & Endpoints](#feature-notes--endpoints) · [Testing & Quality](#testing--quality)
- [Production & SSR Builds](#production--ssr-builds) · [Artisan commands](#artisan-commands) · [Documentation](#documentation) · [Upgrade Notes](#upgrading)

## Stack Highlights
- **Backend – Laravel 13 (PHP 8.4+)** with Sanctum for API tokens, Spatie Permissions for RBAC, Laravel Cashier (Stripe) for subscriptions, a provider-neutral payment layer for the shop, queue/listener scaffolding, and opinionated seeders for fast iteration.
- **Frontend – Vue 3 + Inertia.js 3 + TypeScript** with Ziggy-powered routing, optional server-side rendering, and theme initialization in a single SPA shell.
- **UI & Editor Toolkit** – Tailwind CSS 4, shadcn-vue components on Reka UI, Lucide icons, Vue Sonner toasts, and Tiptap 3 rich text editing for forum and blog content.
- **Data Visualization** – Unovis charts for dashboards inside the admin area.
- **Payments** – Stripe Checkout for shop orders and Cashier for subscriptions, both behind idempotent webhooks (`App\Payments`). Tebex plugs in behind the same `PaymentProvider` contract in a later milestone.
- **Developer Experience** – Vite 8 (Rolldown), ESLint 10 + Prettier, Pint, Larastan (PHPStan level 5), PHPUnit 12, a CI matrix that runs the tests on SQLite, MySQL 8 and PostgreSQL 16, and convenience scripts for running Laravel, queues, SSR, and Vite together.
- **Secure & search-friendly by default** – HTML sanitisation for user content, rate limiting, security headers with a nonce-based CSP, per-page SEO metadata, structured data, an XML sitemap and a dynamic `robots.txt`.
- **Ready for more languages** – English only for now, but interface strings, locale selection and a completeness check (`php artisan lang:check`) are in place (see [docs/i18n.md](docs/i18n.md)).

## Application Modules
- **Forum System**: Boards, threads, post moderation, publishing workflows, and tracking read state with dedicated controllers and routes.
- **Blog & Previewing**: Public blog listing, tokenized preview links, and authenticated commenting APIs.
- **Support Center**: Ticket submission, messaging threads, authenticated access to customer conversations, and configurable
  assignment rules so tickets auto-route to the right agents or support teams without touching the database. Attachments are private and only served through an authorised download route.
- **Shop** (usable end to end; still being completed, see [Setting up the shop](#setting-up-the-shop) and [docs/commerce.md](docs/commerce.md)):
  - **Catalogue**: products with options and variants, one active price in the store currency, brands, categories and tags, and stock tracked in a movement ledger with manual adjustments and low-stock warnings. A product can be free, untaxed, or not need shipping.
  - **Pictures & storefront**: several pictures per product (re-encoded to WebP at three sizes), a catalogue with prices and savings, and a product page with a gallery, option pickers that follow the variants, stock described in words (never counts) and structured data.
  - **Cart & discount codes**: guest and signed-in carts (a guest's cart and orders follow them into their account when they sign in and verify their email); percentage, fixed-amount and free-shipping codes with dates, a minimum spend, usage limits and product or category restrictions.
  - **Checkout**: guest or signed-in, an address book (Settings → Addresses), shipping zones and rates, a tax-rate table and a live quote as the shopper types. Payment happens on hosted Stripe Checkout; stock is held for an unpaid order and released when it expires. Orders that cost nothing are paid on the spot without a provider.
  - **Orders & refunds**: an order lifecycle with an append-only history, receipts and shipping emails, fulfilment with tracking, cancellation, notes, and full or partial refunds through Stripe (its own permission, `commerce.acp.refund`).
  - **Digital goods**: files kept on a private disk, per-order download grants, short-lived signed links, download limits and optional expiry, revoked automatically by a full refund. **Licence keys** are sold from an encrypted pool tied to the stock count (so a key is never sold twice), handed over when the order is paid and hidden again by a full refund.
  - **Gift cards**: staff issue stored value (the code is shown once and never kept, only its hash and last four characters), customers spend it at checkout on the whole order, shipping and tax included, one card per order. An order the card pays in full needs no payment provider, the card's share is given back if the order is not paid, and refunds go back onto the card first. A ledger records every change to a balance.
  - **Wishlists & reviews**: customers save products to a private wishlist and review what they bought (1 to 5 stars, plain text, "Verified purchase" for buyers); staff choose which reviews wait for approval and publish, reject or delete them in the ACP. Ratings show on product cards, the product page and its structured data.
  - **Tebex (in progress)**: connect a Tebex store with its public token and the shop keeps a local copy of its categories and packages (`php artisan tebex:sync`, on a schedule, or from the ACP), shows them as its own storefront, holds them in the cart and, at checkout, makes a basket at Tebex and sends the buyer there. It sells for the games as well as for universal stores: a Minecraft or Overwolf buyer types their username, and a Discord, FiveM, Rust (and other Steam-based game) buyer signs in through Tebex before paying. Tebex works out the tax and takes its own codes and gift cards, and tells the shop by webhook when a payment is made, refunded or disputed (set `TEBEX_WEBHOOK_SECRET`). Buyers pay on Tebex's own page, or with `TEBEX_CHECKOUT_MODE=popup` or `inline` on a page of the shop's with Tebex.js. A recurring package's renewals are recorded as payments and where the recurring payment stands is kept and shown (Tebex charges and ends it). What a purchase gives the buyer's account (a feature, a limit) is set per package, given when the order is paid and taken back with a refund, a lost dispute or the end of a recurring payment ([docs/entitlements.md](docs/entitlements.md)). An **experimental** mode (`TEBEX_CHECKOUT_API_ENABLED`, for stores Tebex has approved for its Checkout API) sells the shop's own products through Tebex instead; it refunds through the API too. **Not finished:** a check against a real store (M2b), so treat it as a preview until then. See [docs/tebex.md](docs/tebex.md).
  - **Admin**: Commerce, Products, Orders, Reviews, Brands and tags, Shipping, Tax rates, Discount codes, Gift cards and Tebex in the ACP.
  - **Not built yet**: selling gift cards as a product, and entitlements from the shop's own products (on the roadmap; Stripe plans give them already).
- **Polls & Surveys**: Polls with admin management, voting and an API.
- **Global Search**: One search across blog posts, forum threads and FAQs with admin analytics. The page searches the database. The Meilisearch index only keeps what a guest can already open; with `SEARCH_DRIVER=meilisearch`, `php artisan metaforge:search:sync` applies its settings and `php artisan metaforge:search:reindex` rebuilds documents ([docs/search-index.md](docs/search-index.md)).
- **Billing & Subscriptions**: Stripe-powered subscriptions via Laravel Cashier, an end-user settings page for plan management, and an admin invoice browser with webhook visibility. Plans give entitlements, and a failed renewal is followed up: the customer is emailed once for each attempt (and when it goes through, or the subscription ends), and can press **Pay now** after fixing their card. A plan can give a new customer a free trial, with a reminder before it ends, and a customer can enter a Stripe promotion code that is checked before they pay, and the billing page links to Stripe's customer portal ([docs/billing.md](docs/billing.md)). A metered-usage example counts each account's API calls per month against what its plan allows, with a usage panel for customers and a report for staff ([docs/usage.md](docs/usage.md)).
- **Admin Control Panel (ACP)**: Inertia-powered layouts under `resources/js/pages/acp` for managing users, forums, support assignment rules, team membership, the shop, and content. Permission middleware ensures only privileged roles can reach moderation endpoints.
- **Admin audit log**: every change made in the admin area is written to an append-only log (who, what it was done to, what changed as it was and as it became, where from, and whether it worked), with a viewer in **Admin → Audit log**, a view of everything done on one account, and a retention setting ([docs/audit-log.md](docs/audit-log.md)).
- **Read-only impersonation**: staff can see the site as one customer sees it, to help with a support case. It needs a reason, lasts 30 minutes, cannot change anything (and does not even keep what looking changes), keeps secrets such as recovery codes and downloads out of view, and writes every page opened to the audit log ([docs/impersonation.md](docs/impersonation.md)).
- **Branded emails**: every email the site sends is in one layout with the site's name and colour (or a logo), a footer, a plain-text version and a dark one. Set `MAIL_BRAND_COLOR`, `MAIL_LOGO_URL` and `MAIL_FOOTER`; the words on buttons and the colour of links are chosen so they can be read on any colour ([docs/email.md](docs/email.md)).
- **"Get started" checklist**: a card on a new customer's dashboard lists what to do first (confirm the email, add a picture or bio, turn on two-factor, choose notifications, choose a plan, make an API key, say hello in the forum). Each step ticks itself from the account, and the card goes when everything is done, when it is hidden, or after 30 days ([docs/onboarding.md](docs/onboarding.md)).
- **Warnings and bans**: a moderator can warn a member, ban them for good or until a date, and lift a ban, all with a reason; each is kept in the member's history, they are emailed and can read it in their account, and a suspension lifts itself when it ends. A warning is only a notice: nothing happens automatically ([docs/moderation.md](docs/moderation.md)).
- **Automod and a review queue for the forum**: administrators set rules (phrases, too many links or a blocked domain, the same text again, too many posts too fast, for everybody or only new accounts) and a post that breaks one is held out of sight until a moderator approves it in a queue, or refused. Held posts tell nobody and earn nothing until approved ([docs/automod.md](docs/automod.md)).
- **Clearing out a spammer**: a moderator takes down everything a member posted in one go (threads unpublished, replies deleted, blog comments and product reviews rejected, what is waiting in the review queue rejected), on its own, together with a ban, or for several members at once from the Users list. Nothing is destroyed for good, the member is not told, and it is written to their history ([docs/moderation.md](docs/moderation.md)).
- **Authentication & Authorization**: Starter-kit authentication (registration, login, password reset, email verification, TOTP two-factor and social login) plus Spatie role/permission gating surfaced to the SPA via dedicated composables.
- **Customer Dashboard**: after sign-in, a customer sees their newest orders and a shortcut to the order pages where their licence keys and downloads are found (when the shop is enabled), their plan, their account security and shortcuts, plus their tickets, forum activity and recommended reading. Areas an administrator has switched off are left off it.
- **Appearance Management**: System/light/dark modes synced between SSR and the client through a reusable composable.

### Website Sections & Feature Toggles
- **Module switches** live in the Admin Control Panel under **System Settings → Website Sections**. Operators can enable or disable major areas—forum, blog, support, shop, billing, and more—without touching code or editing configuration files.
- **Social login toggles** sit alongside those settings so administrators can enable or disable Google, Discord, or Steam OAuth flows in real time. Disabled providers disappear from the login and registration pages, and their redirect/callback routes automatically return `404` responses.
- **Consistent enforcement** is handled by the `EnsureWebsiteSectionIsEnabled` middleware and shared Inertia props. When a section is disabled the corresponding navigation links, global search providers, and HTTP routes automatically return `404` responses for visitors. (The Stripe webhook and a customer's receipt and download links stay reachable so payments in flight still settle.)
- **Safe defaults** ensure newly provisioned environments start with every section available while still honoring explicit `false` values saved in the database, so administrators always see the exact state they configured.

## Project Structure
```
app/
├─ Http/                    # Controllers (web, ACP, API, webhooks), middleware, form requests
├─ Models/ & Policies/      # Eloquent models and the policies that authorise them
├─ Payments/                # Provider-neutral payment layer: contract, Stripe provider, webhook receiver
├─ Support/                 # Domain services: Commerce (pricing, orders, discounts, digital delivery),
│                           #   Billing, Forum, Search, Security, Seo, OAuth, OpenApi, ...
├─ Jobs/ & Console/         # Queued jobs and Artisan commands
config/                     # Including commerce.php, billing.php, security.php, seo.php, i18n.php
database/                   # Migrations, factories and seeders (reference data and demo data)
docs/                       # commerce.md, i18n.md and the v1.0.0 roadmap
lang/                       # Translation files (English)
resources/
├─ js/
│  ├─ app.ts              # Inertia SPA bootstrap
│  ├─ ssr.ts              # Server-side rendering entry
│  ├─ pages/              # Page-level components (dashboard, forum, shop, ACP, auth, settings)
│  ├─ layouts/            # Shared shell layouts
│  ├─ components/         # UI building blocks & shadcn-inspired primitives
│  ├─ composables/        # Reusable logic (auth, appearance, forms, data fetching, i18n)
│  └─ lib/ & types/       # Client-side helpers and TypeScript contracts
└─ css/                   # Tailwind entry point and global styles
tests/                      # PHPUnit feature and unit tests
compose.yaml                # Optional backing services for local development (Docker)
```

## Prerequisites
- **PHP 8.4+** and **Composer 2**, with these extensions (most are on by default; Herd's PHP has all of them):
  - Required: `bcmath` (used by Cashier's money library), `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `session`, `tokenizer`, `xml`, `xmlwriter` and `zip` (account data exports).
  - A PDO driver for your database: `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`.
  - `gd` **with WebP support**, for product pictures. Optional: `exif`, so sideways photos are turned upright.
  - `redis` (phpredis) only if you switch cache, queues or sessions to Redis; the defaults use the database and `.env.example` sets `REDIS_CLIENT=phpredis`.
- **Node.js 22.13 or newer** (24 LTS recommended, see `.nvmrc`) with **npm 10+**.
- **A database**: SQLite (the default, for local development), **MySQL 8** or **PostgreSQL 16**. The test suite runs against all three in CI, and tests always use an in-memory SQLite database unless you ask otherwise.
- **For the shop**: a Stripe account (test keys are enough) and, locally, the [Stripe CLI](https://docs.stripe.com/stripe-cli) to forward webhooks.
- Optional: Docker, for the backing services in `compose.yaml` (see below).
- PHP's `upload_max_filesize` and `post_max_size` limit what staff can upload: product pictures are accepted up to 5 MB and digital files up to 100 MB by default (`COMMERCE_DOWNLOAD_MAX_KB`), but PHP's own defaults are much lower.

## Quick Start
> **Shortcut:** after cloning, `composer setup` installs PHP and Node dependencies, creates `.env`, generates the app key, runs migrations (creating the SQLite file if needed) and builds the frontend. It does not link storage or seed data, so continue with steps 4 and 5 below.

1. **Clone & Install**
   ```bash
   git clone https://github.com/MetaGrenade/laravel-vue.git
   cd laravel-vue
   composer install
   npm install
   ```
2. **Environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Set `APP_URL` to the address you will browse (`http://localhost:8000` for `php artisan serve`, or your `.test` address under Herd or Valet) and update database credentials and any third-party service keys. Mail is written to the log by default (`MAIL_MAILER=log`); point `MAIL_*` at Mailpit (below) to see emails in a browser. The shop's settings are covered in [Setting up the shop](#setting-up-the-shop).
3. **Database**
   ```bash
   php artisan migrate
   ```
   With the default SQLite connection Laravel offers to create `database/database.sqlite` for you. The migrations create every table the app needs, including the session, cache and queue tables used by the default `database` drivers.
4. **Storage link**
   ```bash
   php artisan storage:link
   ```
   Public uploads (product pictures, blog cover images) are served from `storage/app/public`. Private files (support attachments and digital downloads) stay on the `local` disk and never need the link.
5. **Seed Data (optional, local development only)**
   ```bash
   php artisan db:seed
   ```
   This creates the roles and permissions, subscription plans, support categories and badges, plus demo data: a demo shop (products with variants, shipping zones, tax rates and the discount codes `WELCOME10`, `SAVE5` and `FREESHIP`), a poll and a few demo users. The first user (ID 1, `test@example.com`, password `password`) is made an administrator, so you can sign in and open the ACP straight away. **Never seed a production database** (see [Production & SSR Builds](#production--ssr-builds)).

   Extra demo content is available on request and each seeder is safe to skip:
   ```bash
   php artisan db:seed --class=ForumDemoSeeder          # empties the forum tables, then adds categories, boards and multi-page threads
   php artisan db:seed --class=AcpDashboardDemoSeeder   # a year of users, blog posts and tickets for the ACP dashboard charts
   ```
6. **Run the App**
   - Everything in one terminal: `composer dev` (Laravel server, queue worker, **scheduler** and Vite). The scheduler does the scheduled work: releasing the stock of unpaid orders (every five minutes), support SLA checks, lifting suspensions that have ended (every minute).
   - Or separately: `php artisan serve`, `php artisan queue:listen --tries=1`, `php artisan schedule:work` and `npm run dev`
   - Under Laravel Herd or Valet the web server is already running; start the queue worker, the scheduler and Vite (`php artisan queue:listen --tries=1`, `php artisan schedule:work` and `npm run dev`).

### Local services with Docker (optional)
`compose.yaml` provides MySQL, PostgreSQL, Redis, Meilisearch and Mailpit for local development; the application itself still runs on your machine. (Meilisearch is optional. The search page still uses the database. See [docs/search-index.md](docs/search-index.md).)
```bash
docker compose up -d mysql redis mailpit        # choose what you need
docker compose --profile pgsql up -d pgsql      # PostgreSQL is opt-in
docker compose --profile search up -d meilisearch  # optional; then SEARCH_DRIVER=meilisearch, metaforge:search:sync / reindex; the page still uses the database
```
Then point `.env` at them (database, `REDIS_*`, and `MAIL_MAILER=smtp` with `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025` for Mailpit, whose inbox is at http://localhost:8025). Unless `DB_DATABASE` and `DB_PASSWORD` are set in `.env`, the containers use the database `metaforge` and the password `password` (user `root` on MySQL, `postgres` on PostgreSQL). See [CONTRIBUTING.md](CONTRIBUTING.md) for running the tests against each database.

## Setting up the shop
The shop works out of the box for browsing and for orders that cost nothing. Taking payment needs Stripe, a queue worker and the scheduler. [docs/commerce.md](docs/commerce.md) explains how everything fits together.

1. **Stripe credentials.** Put your test keys in `.env`: `STRIPE_KEY`, `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`. Stripe is only offered at checkout when both the secret key and the webhook signing secret are set, because without them a payment could never be confirmed.
2. **Webhooks.** In development forward Stripe's events with the CLI and use the signing secret it prints (see [Billing & Subscription Management](#billing--subscription-management)):
   ```bash
   stripe listen --forward-to http://localhost:8000/stripe/webhook
   ```
   For a real endpoint, enable the shop's events as well as the subscription ones: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, and for refunds `refund.created`, `refund.updated`, `refund.failed` and `charge.refunded`.
3. **Run a queue worker and the scheduler** (`composer dev` runs both; in production see [Production & SSR Builds](#production--ssr-builds) for the queue worker and the cron entry). Unpaid orders expire and release their stock through the scheduler; run `php artisan commerce:expire-orders` to do it by hand.
4. **Configure the store in the ACP** (**Commerce** in the sidebar). Sign in as an administrator and:
   - set the currency in `.env` (`COMMERCE_CURRENCY`, before you add prices) and, if you like, the payment provider under **System settings**;
   - add **Shipping** zones and rates and **Tax rates**. With no shipping zones, orders ship anywhere for free, and with no active tax rate no tax is charged, which is fine for digital shops;
   - create **Products** (details, price, options and variants, stock, pictures and downloads), and **Discount codes**.
5. **Roles.** The `admin` role can do everything. Give other staff roles the `commerce.acp.*` permissions they need under **Access control**; refunds are a separate permission (`commerce.acp.refund`) because they cannot be undone.
6. **Licence keys** (optional). To sell keys, open a product's **Licence keys** tab, switch them on and paste the keys in. They are stored encrypted with your app key, so **keep `APP_KEY` safe and backed up** along with the database: without it the keys cannot be read.

Settings that can be changed in `.env` (full table in [docs/commerce.md](docs/commerce.md#configuration)):

| Variable | Default | Purpose |
|----------|---------|---------|
| `COMMERCE_CURRENCY` | `USD` | The one currency the shop sells in. |
| `COMMERCE_PROVIDER` | `stripe` | Store-level payment provider (the ACP can override it). |
| `COMMERCE_GUEST_CHECKOUT` | `true` | Allow buying without an account. |
| `COMMERCE_PAYMENT_WINDOW` | `60` | Minutes stock is held for an unpaid order (minimum 30). |
| `COMMERCE_ORDER_PREFIX` | `MF` | Prefix of the order number. |
| `COMMERCE_LOW_STOCK_THRESHOLD` | `5` | Stock at or below this is flagged as low. |
| `COMMERCE_IMAGE_DISK` | `public` | Disk for product pictures; it must be served by the web (`php artisan storage:link` for `public`). |
| `COMMERCE_IMAGE_MAX_PIXELS` | `16000000` | Largest picture accepted (width × height). |
| `COMMERCE_DOWNLOAD_DISK` | `local` | Disk for digital files; it must be private. |
| `COMMERCE_DOWNLOAD_MAX_KB` | `102400` | Largest digital file, in kilobytes. |
| `COMMERCE_DOWNLOAD_LIMIT` | `10` | Downloads of each file per order (`0` = unlimited). |
| `COMMERCE_DOWNLOAD_EXPIRY_DAYS` | `0` | Days after payment that downloads stop (`0` = never). |
| `COMMERCE_DOWNLOAD_LINK_MINUTES` | `30` | How long a download link on the order page works. |
| `COMMERCE_GIFT_CARDS` | `true` | Accept gift cards at checkout (`false` hides the box; cards and balances are kept). |
| `COMMERCE_WISHLIST_MAX` | `200` | Most products one customer can save. |
| `COMMERCE_REVIEWS` | `true` | Allow and show product reviews. |
| `COMMERCE_REVIEWS_REQUIRE_PURCHASE` | `false` | Only customers with a paid order for the product can review it. |
| `COMMERCE_REVIEWS_APPROVAL` | `unverified` | Which reviews wait for staff: `all`, `unverified` (those not from buyers) or `none`. |

## Realtime Broadcasting & Pusher Setup
The starter comes pre-wired for private and presence channels using Laravel Echo and Pusher. If Pusher
credentials are missing the app safely falls back to the log broadcaster, so broadcasts will still
dispatch but nothing will be pushed to clients. To enable realtime notifications:

1. **Create a Pusher app**
   - [Sign in to Pusher Channels](https://dashboard.pusher.com/) and create an app that matches your
     development region.
   - Copy the **App ID**, **Key**, **Secret**, and **Cluster** values.

2. **Configure backend environment variables**
   Update the `.env` file with the credentials you copied. Optional values let you point to a Pusher
   compatible service or self-hosted stack.
   ```ini
   BROADCAST_CONNECTION=pusher
   PUSHER_APP_ID=your-app-id
   PUSHER_APP_KEY=your-app-key
   PUSHER_APP_SECRET=your-app-secret
   PUSHER_APP_CLUSTER=mt1        # Change if your app uses a different cluster
   PUSHER_HOST=                  # Set when using a custom host (e.g. laravel-websockets)
   PUSHER_PORT=                  # Defaults to 443 for https / 80 for http when left blank
   PUSHER_SCHEME=https           # Set to http when tunneling over plain websockets
   PUSHER_FORCE_TLS=true         # Override when terminating TLS elsewhere
   PUSHER_VERIFY_SSL=true        # Disable only when using self-signed certificates in dev
   # PUSHER_CA_BUNDLE=/path/to/cacert.pem
   ```
   The `config/broadcasting.php` helper will automatically coerce ports, TLS flags, and certificate
   verification settings based on these values.

3. **Sync frontend Vite environment variables**
   Echo reads matching `VITE_` variables in `resources/js/lib/echo.ts`. Mirror the backend values so
   the client can connect to the same websocket endpoint.
   ```ini
   VITE_BROADCAST_DRIVER=pusher
   VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
   VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
   VITE_PUSHER_HOST="${PUSHER_HOST}"    # Remove this line entirely to use the cluster host
   VITE_PUSHER_PORT="${PUSHER_PORT}"    # Remove to fall back to 443 (https) or 80 (http)
   VITE_PUSHER_SCHEME="${PUSHER_SCHEME}"
   VITE_PUSHER_FORCE_TLS="${PUSHER_FORCE_TLS}"
   ```

   Blank `VITE_PUSHER_HOST` / `VITE_PUSHER_PORT` values fall back to the Pusher cluster host and the
   default port for the scheme. Echo and the Pusher client are only downloaded for signed-in users when a
   key is configured.

4. **Restart local tooling**
   After editing `.env` and `.env.local`, restart `php artisan serve` and Vite so both processes pick up
   the changes. New private channel subscriptions will now authenticate through `/broadcasting/auth`
   and emit toast notifications in realtime.

## Security
- **User content is sanitised on write.** Forum threads/replies and blog bodies pass through `App\Support\Security\HtmlSanitizer` (Symfony HtmlSanitizer) via the `SanitizedHtml` Eloquent cast, so content rendered with `v-html` is safe. Forum content only keeps the markup the editor can produce; blog content allows the full set of safe elements. Run `php artisan content:sanitize` once after upgrading from an older version to clean previously stored content.
- **Rate limiting.** Named limiters (see `AppServiceProvider::configureRateLimiting()`) protect registration, password resets, password confirmation, two-factor challenges, posting, reports, search, billing, discount codes and downloads. API routes are throttled per token.
- **Security headers & CSP.** `App\Http\Middleware\SecurityHeaders` sends `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS (over HTTPS, production by default) and a nonce-based Content Security Policy. Tune everything in `config/security.php`; set `CSP_REPORT_ONLY=true` to trial changes. When adding third-party scripts, styles or APIs, add their origins to the relevant directive. The suite checks these on every CI run ([docs/security-headers.md](docs/security-headers.md)).
- **Least-privilege route exposure.** Ziggy route groups (`config/ziggy.php`) only publish admin control panel routes to staff.
- **Production defaults.** Stricter password rules (12+ characters, mixed case, numbers, breach check), destructive database commands are blocked, and `APP_FORCE_HTTPS=true` generates HTTPS URLs behind a proxy. Remember to set `SESSION_SECURE_COOKIE=true` when serving over HTTPS.
- **Social sign-in trusts verified addresses only.** An email address from Google or Discord is only used to recognise an account (or to mark a new one verified) when the provider says it verified it.
- **Hardened serialization.** Sessions use Laravel 13's JSON serialization and the cache only unserializes an explicit allow-list of classes (`config/cache.php`).
- **Private files and safe uploads.** Support attachments and digital downloads live on private disks and are only served through authorised routes (digital files as signed, short-lived, download-limited attachments). Product pictures are decoded and re-encoded, so anything hidden in an upload does not survive. Prices, shipping and tax are always recalculated on the server, never read back from the cart or the form.
- **Reporting a vulnerability.** Please do not open a public issue. The contact is also published at `/.well-known/security.txt`. See [SECURITY.md](SECURITY.md) and [docs/security-contact.md](docs/security-contact.md).

## SEO
- **Per-page metadata.** Controllers describe a page with the request-scoped `App\Support\Seo\Seo` service:
  ```php
  app(Seo::class)
      ->title($post->title)
      ->description($post->excerpt)
      ->image($coverUrl)
      ->article(publishedAt: $post->published_at, author: $post->author->name)
      ->schema(['@type' => 'BlogPosting', 'headline' => $post->title]);
  ```
  Title, description, canonical URL, Open Graph, Twitter and robots tags plus JSON-LD are rendered server-side in `resources/views/app.blade.php` (so crawlers that don't run JavaScript see them) and kept up to date during client-side navigation through Inertia's `serverHead` option. A page's own `<Head title="…">` still sets the document title.
- **Defaults** (site name, description, share image, Twitter handle, home page title and description) come from `config/seo.php` / `SEO_*` environment variables.
- **Indexing** is enabled in production only (override with `SEO_INDEXING`). Account, admin, auth and other private routes are always `noindex` (`seo.noindex_routes`).
- **Sitemap & robots.** `/sitemap.xml` lists public pages, published posts, forum boards/threads and active products for the enabled website sections (cached for an hour). `/robots.txt` blocks private areas and references the sitemap, or blocks everything when indexing is disabled. A web manifest is generated from configuration.
- **Server-side rendering** gives crawlers fully rendered HTML: run `npm run build:ssr`, start `php artisan inertia:start-ssr` (e.g. under Supervisor) and set `INERTIA_SSR_ENABLED=true`. If the SSR server is unavailable, pages fall back to client-side rendering.

## Upgrading
- **From any earlier pre-1.0 build:** read the **Unreleased** section of [CHANGELOG.md](CHANGELOG.md), run `php artisan migrate`, and re-run `php artisan db:seed --class=RolePermissionSeeder` so new permissions (such as `commerce.acp.refund`) exist. The shop's upgrade notes, including behaviour changes and the Stripe events to add, are in [docs/commerce.md](docs/commerce.md#upgrading-an-existing-installation). Run `php artisan storage:link` if you have not (product pictures need it) and `php artisan support:attachments:privatize` once to move old support attachments off the public disk.

### Laravel 12 → 13
- PHP **8.4** and Node **22.13+** are now required.
- Sessions are serialized as JSON, so existing sessions are invalidated after deploying and users need to sign in again.
- Run `php artisan migrate` (adds the Cashier 16 `subscription_items` meter columns) and `php artisan content:sanitize`.
- Stripe: Cashier 16 uses Stripe API version `2025-07-30.basil`. Review the [Cashier upgrade guide](https://github.com/laravel/cashier-stripe/blob/16.x/UPGRADE.md) if you use metered billing or coupons.
- Frontend: shadcn-vue components now use Reka UI. Checkbox and Switch bind with `v-model` / `:model-value` (not `v-model:checked`), icons are imported from `@lucide/vue`, and Tailwind is configured in `resources/css/app.css` (there is no `tailwind.config.js`).

## HTTP API & Swagger Docs
- **Versioned endpoints** live under `/api/v1`. Public consumers can fetch published blog posts and forum threads, while
  authenticated clients may create personal access tokens and retrieve their profile details.
- **API keys**: anyone signed in can make their own in **Settings → API keys**, limited to the scopes they tick and expiring when they
  say, then send it as a bearer token in the `Authorization` header. A key that lacks the scope a route needs is refused with a 403
  that names it, and a revoked or expired key with a 401 ([docs/api-keys.md](docs/api-keys.md)). Staff manage keys in **Admin → Tokens**.
- **Signing in over the API**: exchange valid web credentials for a key with `POST /api/v1/auth/token` (optionally asking for fewer
  scopes), and give it back with `DELETE /api/v1/auth/token`, which revokes it.
- **Interactive documentation** is available at [`/api/docs`](http://localhost:8000/api/docs) once the Laravel server is
  running. The page embeds Swagger UI and reads the generated OpenAPI schema from `/api/docs/openapi.json`.
- **Generate or refresh the OpenAPI schema** with `php artisan api:docs`. The command writes the latest description to
  `storage/app/api-docs/openapi.json`, which the Swagger UI consumes.

## OAuth & Social Login
The starter ships with first-party integrations for Google, Discord, and Steam built on top of the custom OAuth service layer under `app/Support/OAuth`. Configure each provider before attempting to sign in or link identities.

1. **Register credentials with each provider**
    - Create a Google OAuth client and enable the People API.
    - Configure a Discord application with the `identify` and `email` scopes enabled.
    - Generate a Steam Web API key from the Steam partner portal.

2. **Update environment variables**
   Edit your `.env` file and paste the provider credentials. Callback URLs default to `${APP_URL}/auth/oauth/{provider}/callback` so you can reuse the same redirect across environments.
   ```ini
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/oauth/google/callback"
   DISCORD_CLIENT_ID=...
   DISCORD_CLIENT_SECRET=...
   DISCORD_REDIRECT_URI="${APP_URL}/auth/oauth/discord/callback"
   STEAM_API_KEY=...
   STEAM_REDIRECT_URI="${APP_URL}/auth/oauth/steam/callback"
   ```

3. **Verify service configuration**
   The provider credentials are consumed through `config/services.php`, so the application can resolve tokens during the OAuth handshake. Custom providers are registered via `App\Providers\OAuthServiceProvider` and exposed through `/auth/oauth/{provider}/redirect` and `/auth/oauth/{provider}/callback` routes handled by `SocialLoginController`.

4. **Linking identities**
    - **End users** can link or unlink accounts from the security settings screen at `/settings/security`, which calls the `settings.security.social` routes.
    - **Administrators** can assign provider identities while editing a user in the ACP (`/acp/users/{id}/edit`). The controller reuses existing rows per provider and protects against attaching IDs that are already linked to other accounts.

5. **Steam return URL requirements**
   Steam expects an exact match for the return URL. If you deploy the application to a different host name, update `STEAM_REDIRECT_URI` accordingly and add the domain in the Steam partner dashboard to avoid 403 responses during the handshake.

## Daily Development Workflow
- **SPA Bootstrapping**: `resources/js/app.ts` registers Inertia, Ziggy, and the global progress indicator while invoking theme initialization for light/dark support.
- **SSR Rendering**: `resources/js/ssr.ts` mirrors the client bootstrapping, exposing Ziggy routes globally so `route()` works during server rendering and email previews.
- **Theming**: `useAppearance()` stores preferences in localStorage and cookies, ensuring consistency between SSR and the browser.
   ```ts
   const { appearance, updateAppearance } = useAppearance();
   updateAppearance('dark');
   ```
- **Role & Permission Checks**: Use the provided composables to guard UI.
   ```ts
   const { hasRole } = useRoles();
   const { hasPermission } = usePermissions();

   const isAdmin = computed(() => hasRole('admin|super-admin'));
   const canManageUsers = computed(() => hasPermission('users.acp.manage'));
   ```
- **Translations**: Use `useI18n()` (`t()` / `tc()`) for new interface text and add the keys to `lang/en`; see [docs/i18n.md](docs/i18n.md). The shop pages still use literal English for now.
- **Queues & Background Work**: `composer dev` also starts `queue:listen` so job dispatches from forum moderation, notifications or shop emails run instantly during development. Scheduled work runs from `php artisan schedule:work`, which `composer dev` starts as well.

## Feature Notes & Endpoints
- **Forum moderation routes** handle publishing, locking, pinning, reporting, and deletion, guarded by role middleware (`role:admin|editor|moderator`).
- **Support ticket routes** provide authenticated creation, viewing, and messaging flows for end-users. Ticket creation in both
  the public portal and Admin Control Panel automatically runs through the Support Assignment Rules engine.
- **Blog previews** use signed tokens so editors can review drafts before publishing.
- **Shop routes**: `/shop` (catalogue and product pages), `/cart`, `/checkout`, the signed order confirmation page, `/orders` (a customer's history), `/downloads/{grant}/{file}` (signed, rate-limited file delivery) and `/settings/addresses`. The ACP side lives under `/acp/commerce`.
- **Billing settings** live under `/settings/billing` and expose plan selection, payment method updates, invoices, and
  cancellation flows for authenticated customers. ACP users can review invoices at `/acp/billing/invoices`.

### Account Security
- **Security settings**: Manage active browser sessions, enable time-based one-time password (TOTP) multi-factor authentication,
  and rotate recovery codes from `/settings/security`. Recovery codes are generated locally and encrypted before storage so
  users must download them immediately after confirmation.
- **Session management**: The sessions panel lists every active session stored in the database. Revoking a session removes it
  from the table and invalidates its cookie, forcing re-authentication on that device. The current session is highlighted and
  protected from accidental revocation.
- **MFA enrollment flow**:
  1. Generate a fresh secret which exposes a manual key and otpauth URL for QR creation.
  2. Confirm the secret by submitting a 6-digit code from an authenticator app (e.g., 1Password, Google Authenticator).
  3. Store the displayed recovery codes—each is single-use and can be regenerated on demand after confirmation.
- **Disabling MFA**: Clearing multi-factor authentication wipes the stored secret and recovery codes immediately, returning the
  account to password-only authentication.

### Support Operations (Assignment & SLA)
- **Assignment Rules**: Configure routing with ordered rules stored in the `support_assignment_rules` table. Rules can target
  specific categories or priorities and route tickets to individual agents (`assignee_type = 'user'`) or entire support teams
  (`assignee_type = 'team'`). They are evaluated top-to-bottom until a match assigns the ticket.
- **Team Membership Management**: Support teams expose membership editing from the ACP so administrators can curate which
  agents receive team-targeted assignments without artisan commands or direct database updates.
- **Audit Trail**: All automated changes (assignments, escalations, and SLA-driven reassignments) are captured in the
  `support_ticket_audits` table for traceability.
- **SLA Monitoring**: The `MonitorSupportTicketSlas` job runs every fifteen minutes (see `bootstrap/app.php`) and will escalate
  ticket priorities or reassign unattended tickets based on the thresholds defined in `config/support.php`.
- **Scheduler Setup**: Ensure the Laravel scheduler is running in production. Add a cron entry for `php artisan schedule:run` (or run
  `php artisan schedule:work` during development) alongside the queue worker so SLA adjustments, search statistics and expiring unpaid shop orders happen continuously.
- **Customising Thresholds**: Update `config/support.php` to tune escalation windows or disable specific behaviours. After editing the
  configuration, clear the cache (`php artisan config:clear`) so the scheduler picks up the changes.

#### Example: Creating a Support Assignment Rule via Tinker
```bash
php artisan tinker
>>> \App\Models\SupportAssignmentRule::create([
...     'support_ticket_category_id' => 1, // optional
...     'priority' => 'high',              // optional
...     'assignee_type' => 'team',         // 'user' (default) or 'team'
...     'support_team_id' => 2,            // required when targeting a team
...     'assigned_to' => null,             // required when targeting a user
...     'position' => 10,
... ]);
```
Rules with lower `position` values are evaluated first, so place the most specific rules near the top of the list. When
assigning to a single agent set `assignee_type` to `user` and provide an `assigned_to` user ID instead of a `support_team_id`.

### Billing & Subscription Management
- **Stripe credentials**: Populate `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` in `.env`. The same keys serve
  subscriptions and the shop. Map each `subscription_plans` record to the correct Stripe Price ID via `config/billing.php`
  (the `STRIPE_PRICE_*` variables, then `php artisan db:seed --class=SubscriptionPlanSeeder`) so subscriptions bill against
  real products.
- **Stripe CLI**: When running `stripe listen` locally, export the printed signing secret to `STRIPE_CLI_WEBHOOK_SECRET`
  (or append it to a comma-separated `STRIPE_WEBHOOK_SECRET`). The webhook handler checks both values so you can keep
  your production secret alongside the ephemeral CLI secret without editing `.env` between sessions.
- **Payment collection**: `/settings/billing` renders Stripe's Payment Element. Users create PaymentMethods client-side and the
  backend finalises subscriptions through Cashier's `newSubscription()->create()` API, including SCA flows. No plain text
  payment method IDs are accepted.
- **Webhooks**: Stripe forwards events to `/stripe/webhook`. Subscriptions need the events Cashier handles (for example
  `invoice.payment_succeeded` and `customer.subscription.deleted`); the shop needs the `checkout.session.*` and refund events
  listed in [Setting up the shop](#setting-up-the-shop). Use the Stripe CLI during development, without an `--events` filter so
  nothing the app listens for is dropped:

  ```bash
  stripe listen --forward-to http://localhost:8000/stripe/webhook
  ```

  Webhook payloads are persisted to `billing_webhook_calls` for auditing, shop events are processed once even if Stripe
  redelivers them, and the handler reconciles invoices in `billing_invoices`.
- **Testing fixtures**: Sample webhook JSON lives in `tests/Fixtures/stripe/`. Feature tests under
  `tests/Feature/Webhooks/StripeWebhookTest.php` exercise invoice and subscription webhook flows. Use Stripe's test keys when
  exercising end-to-end subscription flows locally.
- **Queues & jobs**: Webhook handling and subscription updates rely on queued jobs. Ensure `queue:listen` (or a Supervisor job)
  is running in development and production so invoice syncing happens promptly.

## Testing & Quality
- **PHPUnit**: `composer test` (or `php artisan test`). Tests use an in-memory SQLite database whatever `.env` says, so they never touch your data. To try MySQL or PostgreSQL see [CONTRIBUTING.md](CONTRIBUTING.md#testing-on-other-databases).
- **Static Analysis & Formatting**:
  - Lint Vue/TypeScript: `npm run lint` (fix) / `npm run lint:check`
  - Type-check Vue/TypeScript: `npm run types:check`
  - Check formatting: `npm run format:check` / format: `npm run format`
  - PHP style: `./vendor/bin/pint` (fix) / `./vendor/bin/pint --test`
  - PHP static analysis (Larastan, level 5): `composer analyse`
  - Translation completeness: `php artisan lang:check`

  CI (`.github/workflows`) runs the test suite on SQLite, MySQL 8 and PostgreSQL 16, then Pint, Prettier, ESLint, vue-tsc, Larastan and `lang:check`, plus dependency audits and a secrets scan (gitleaks, every commit on the branch) on every push and pull request. Two scheduled jobs repeat the suite in random order and, each week, re-check dependency advisories and read the branch again for a secret. The front-end checks only look at `resources/`. See [docs/secrets-scan.md](docs/secrets-scan.md).

## Production & SSR Builds
- **Frontend build**: `npm run build` outputs versioned assets for Laravel's Vite integration.
- **SSR build**: `npm run build:ssr` compiles the SPA and SSR bundle; run `php artisan inertia:start-ssr` and set `INERTIA_SSR_ENABLED=true`. Combine with `composer dev:ssr` when testing server rendering locally.
- **Database**: run `php artisan migrate --force`. Do **not** run a plain `php artisan db:seed` in production: it creates a demo user with a known password and demo content. Seed only what you need, for example `php artisan db:seed --class=RolePermissionSeeder --force`, plus `SupportTicketCategorySeeder`, `BadgeSeeder` and `SubscriptionPlanSeeder` if you use those features. `RolePermissionSeeder` makes the user with ID 1 an administrator, so register your own account first, or run `User::find($id)->assignRole('admin')` in `php artisan tinker` for another account.
- **Processes**: keep a queue worker running (for example under Supervisor), add a cron entry for `php artisan schedule:run` every minute, and run `php artisan storage:link`. The shop relies on both: order emails (receipts, shipping and refund notices) are queued, and unpaid orders only expire when the scheduler runs.
- **Env hardening**: Configure HTTPS (`SESSION_SECURE_COOKIE=true`, `APP_FORCE_HTTPS` behind a proxy), queues (e.g., Redis), the scheduler, mail drivers, your live Stripe keys and webhook endpoint, and `APP_ENV=production` with `APP_DEBUG=false` in `.env` before deploying. Run `php artisan optimize` during deployment.
- **Files**: keep the digital download disk private. If you move uploads to S3 or similar, set `COMMERCE_IMAGE_DISK` and `COMMERCE_DOWNLOAD_DISK` to the matching disks (and keep the AWS_* values in `.env`).

## Artisan commands
| Command | What it does |
|---------|--------------|
| `composer setup` | First-time setup: install dependencies, create `.env`, generate the key, migrate and build assets. |
| `composer dev` / `composer dev:ssr` | Run the server, queue worker, scheduler and Vite (the SSR variant builds SSR first and adds log tailing and the SSR server). |
| `php artisan commerce:expire-orders` | Cancel unpaid orders past their payment window and release their stock (the scheduler runs this every five minutes). |
| `php artisan lang:check` | Fail if a language is missing keys the fallback language has. |
| `php artisan api:docs` | Regenerate the OpenAPI schema used by Swagger UI. |
| `php artisan content:sanitize [--dry-run]` | Re-sanitise stored forum and blog HTML. |
| `php artisan support:attachments:privatize [--dry-run]` | Move support attachments from the public disk to the private one. |

## Documentation
- [docs/commerce.md](docs/commerce.md): how the shop works (flow, orders, pricing, shipping, tax, discounts, digital goods, refunds, webhooks, configuration and upgrade notes).
- [docs/billing.md](docs/billing.md): subscriptions, what a customer is told and can do when a payment fails, and the Stripe events the shop needs.
- [docs/entitlements.md](docs/entitlements.md): what a purchase or a subscription plan gives an account (features and limits), when it is given and taken back, and how code asks for it.
- [docs/email.md](docs/email.md): how the emails look, the settings for their name, colour, logo and footer, and how to write a notification that fits.
- [docs/automod.md](docs/automod.md): the forum's automatic moderation: the rules, what a held post is, the review queue and the API's answers.
- [docs/moderation.md](docs/moderation.md): warnings and bans, who may moderate whom, what a member is told, and the permissions.
- [docs/onboarding.md](docs/onboarding.md): the "Get started" checklist on the dashboard, its steps and how to add one.
- [docs/ownership.md](docs/ownership.md): who a record belongs to, why access goes through the owner and never a user id, how that is tested, and what changes when teams arrive in 1.1.
- [docs/i18n.md](docs/i18n.md): how translations, locale selection and the completeness check fit together.
- [docs/ROADMAP-v1.0.0.md](docs/ROADMAP-v1.0.0.md): the plan and milestones to 1.0.0.
- [CHANGELOG.md](CHANGELOG.md), [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## Contributing
Issues and pull requests are welcome! Read [CONTRIBUTING.md](CONTRIBUTING.md) first; it covers setup, the checks every change must pass and how to write code that works on all supported databases. Please include tests or updates to this documentation when modifying setup steps, tooling, or major features. Report security problems privately as described in [SECURITY.md](SECURITY.md).

## Useful Links

- [Laravel Documentation](https://laravel.com/docs/13.x)
- [Vue.js Documentation](https://vuejs.org/guide/quick-start.html)
- [Inertia.js Documentation](https://inertiajs.com/docs/v3/getting-started/index)
- [Laravel Starter Kits](https://laravel.com/docs/13.x/starter-kits#vue)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)
- [shadcn-vue Component Library](https://www.shadcn-vue.com/)
- [Lucide Icons](https://lucide.dev/icons/)
- [Vue Sonner Toast Component](https://vue-sonner.vercel.app/)
- [Tiptap Editor](https://tiptap.dev/docs/editor/getting-started/install/vue3)
- [Stripe CLI](https://docs.stripe.com/stripe-cli)

## Additional Tips

- **Layout Height Utilities**: `html`, `body`, and `#app` are set to `height: 100%` in `resources/views/app.blade.php` so flex layouts and `h-full` panels render as expected across the SPA.
- **N+1 queries**: In the `local` environment lazy loading is detected and logged as a warning (`storage/logs`), so missing eager loads show up during development.
- **Storage Symlink**: Run `php artisan storage:link` after provisioning to expose public uploads (blog cover images, product pictures) served from `storage/app/public`. Support-ticket attachments and digital downloads are private: they live on the disks named by `SUPPORT_ATTACHMENT_DISK` and `COMMERCE_DOWNLOAD_DISK` (both default to `local`) and are only served through authorised download routes.
- **Keep Docs Current**: When introducing new tooling, scripts, or workflows, update this README so onboarding remains frictionless for future contributors.

## License
This project is open-sourced under the [MIT License](LICENSE.md).
