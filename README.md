# MetaForge

A batteries-included boilerplate for building SaaS products, online shops and large community websites with Laravel and Vue. The project ships with a production-ready forum, blog, support center, and admin tooling so teams can focus on features rather than scaffolding. Inertia.js keeps the frontend and backend in sync, Tailwind CSS powers the design system, and first-class TypeScript support ensures maintainable UI code.

![Forum Page Example](https://i.imgur.com/gYNFkFl.png)

> **Status: pre-1.0.** The foundation is in place and the road to a stable 1.0.0 (checkout and orders, Stripe and Tebex payments, plan entitlements, search at scale and more) is laid out in [docs/ROADMAP-v1.0.0.md](docs/ROADMAP-v1.0.0.md). Notable changes are recorded in [CHANGELOG.md](CHANGELOG.md).

## Stack Highlights
- **Backend – Laravel 13 (PHP 8.4+)** with Sanctum for API tokens, Spatie Permissions for RBAC, Laravel Cashier (Stripe) for billing, queue/listener scaffolding, and opinionated seeders for fast iteration.
- **Frontend – Vue 3 + Inertia.js 3 + TypeScript** with Ziggy-powered routing, optional server-side rendering, and theme initialization in a single SPA shell.
- **UI & Editor Toolkit** – Tailwind CSS 4, shadcn-vue components on Reka UI, Lucide icons, Vue Sonner toasts, and Tiptap 3 rich text editing for forum and blog content.
- **Data Visualization** – Unovis charts for dashboards inside the admin area.
- **Developer Experience** – Vite 8 (Rolldown), ESLint 10 + Prettier, Pint, PHPUnit 12, and convenience scripts for running Laravel, queues, SSR, and Vite together.
- **Secure & search-friendly by default** – HTML sanitisation for user content, rate limiting, security headers with a nonce-based CSP, per-page SEO metadata, structured data, an XML sitemap and a dynamic `robots.txt`.

## Application Modules
- **Forum System**: Boards, threads, post moderation, publishing workflows, and tracking read state with dedicated controllers and routes.
- **Blog & Previewing**: Public blog listing, tokenized preview links, and authenticated commenting APIs.
- **Support Center**: Ticket submission, messaging threads, authenticated access to customer conversations, and configurable
  assignment rules so tickets auto-route to the right agents or support teams without touching the database.
- **Shop (in progress)**: Product catalogue with variants, prices and inventory, a cart and an order history page. Checkout and payment are on the 1.0 roadmap.
- **Polls & Surveys**: Polls with admin management, voting and an API.
- **Global Search**: One search across blog posts, forum threads and FAQs with admin analytics.
- **Billing & Subscriptions**: Stripe-powered subscriptions via Laravel Cashier, an end-user settings page for plan management, and an admin invoice browser with webhook visibility.
- **Admin Control Panel (ACP)**: Inertia-powered layouts under `resources/js/pages/acp` for managing users, forums, support assignment rules, team membership, and content. Permission middleware ensures only privileged roles can reach moderation endpoints.
- **Authentication & Authorization**: Starter-kit authentication (registration, login, password reset, email verification, TOTP two-factor and social login) plus Spatie role/permission gating surfaced to the SPA via dedicated composables.
- **Appearance Management**: System/light/dark modes synced between SSR and the client through a reusable composable.

### Website Sections & Feature Toggles
- **Module switches** live in the Admin Control Panel under **System Settings → Website Sections**. Operators can enable or disable major areas—forum, blog, support, billing, and more—without touching code or editing configuration files.
- **Social login toggles** sit alongside those settings so administrators can enable or disable Google, Discord, or Steam OAuth flows in real time. Disabled providers disappear from the login and registration pages, and their redirect/callback routes automatically return `404` responses.
- **Consistent enforcement** is handled by the `EnsureWebsiteSectionIsEnabled` middleware and shared Inertia props. When a section is disabled the corresponding navigation links, global search providers, and HTTP routes automatically return `404` responses for visitors.
- **Safe defaults** ensure newly provisioned environments start with every section available while still honoring explicit `false` values saved in the database, so administrators always see the exact state they configured.

## Project Structure
```
resources/
├─ js/
│  ├─ app.ts              # Inertia SPA bootstrap
│  ├─ ssr.ts              # Server-side rendering entry
│  ├─ pages/              # Page-level components (dashboard, forum, ACP, auth, settings)
│  ├─ layouts/            # Shared shell layouts
│  ├─ components/         # UI building blocks & shadcn-inspired primitives
│  ├─ composables/        # Reusable logic (auth, appearance, forms, data fetching)
│  └─ lib/ & types/       # Client-side helpers and TypeScript contracts
└─ css/                   # Tailwind entry point and global styles
```

## Prerequisites
- PHP 8.4+ with Composer 2.
- Node.js 22.13+ (24 LTS recommended, see `.nvmrc`) with npm.
- A database: SQLite (the default, for local development and tests), **MySQL 8** or **PostgreSQL 16**. CI runs the whole test suite on all three. Configure credentials in `.env`.
- Optional: Docker, for the backing services in `compose.yaml` (see below).

## Quick Start
> **Shortcut:** after cloning, `composer setup` installs PHP and Node dependencies, creates `.env`, generates the app key, runs migrations and builds the frontend.

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
   Update database credentials and any third-party service keys.
3. **Database**
   ```bash
   php artisan migrate
   ```
   The migration set creates the `support_assignment_rules`, `support_ticket_audits`, and subscription billing tables used by
   the support operations and Stripe integration features.
4. **Seed Demo Content (optional)**
   ```bash
   php artisan db:seed --class=ForumDemoSeeder
   ```
   The seeder resets forum tables, creates realistic categories/boards, and populates multi-page threads for pagination testing.
5. **Run the App**
   - Start Laravel: `php artisan serve`
   - Start Vite dev server: `npm run dev`
   - Or run everything (Laravel, queues, and Vite) in one terminal: `composer dev`

### Local services with Docker (optional)
`compose.yaml` provides MySQL, PostgreSQL, Redis, Meilisearch and Mailpit for local development; the application itself still runs on your machine.
```bash
docker compose up -d mysql redis mailpit        # choose what you need
docker compose --profile pgsql up -d pgsql      # PostgreSQL is opt-in
```
Then point `.env` at them (database, `REDIS_*`, and `MAIL_*` for Mailpit on `127.0.0.1:1025`). See [CONTRIBUTING.md](CONTRIBUTING.md) for running the tests against each database.

### Realtime Broadcasting & Pusher Setup
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
- **Rate limiting.** Named limiters (see `AppServiceProvider::configureRateLimiting()`) protect registration, password resets, password confirmation, two-factor challenges, posting, reports, search and billing endpoints. API routes are throttled per token.
- **Security headers & CSP.** `App\Http\Middleware\SecurityHeaders` sends `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS (over HTTPS, production by default) and a nonce-based Content Security Policy. Tune everything in `config/security.php`; set `CSP_REPORT_ONLY=true` to trial changes. When adding third-party scripts, styles or APIs, add their origins to the relevant directive.
- **Least-privilege route exposure.** Ziggy route groups (`config/ziggy.php`) only publish admin control panel routes to staff.
- **Production defaults.** Stricter password rules (12+ characters, mixed case, numbers, breach check), destructive database commands are blocked, and `APP_FORCE_HTTPS=true` generates HTTPS URLs behind a proxy. Remember to set `SESSION_SECURE_COOKIE=true` when serving over HTTPS.
- **Hardened serialization.** Sessions use Laravel 13's JSON serialization and the cache only unserializes an explicit allow-list of classes (`config/cache.php`).

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
- **Defaults** (site name, description, share image, Twitter handle) come from `config/seo.php` / `SEO_*` environment variables.
- **Indexing** is enabled in production only (override with `SEO_INDEXING`). Account, admin, auth and other private routes are always `noindex` (`seo.noindex_routes`).
- **Sitemap & robots.** `/sitemap.xml` lists public pages, published posts, forum boards/threads and active products for the enabled website sections (cached for an hour). `/robots.txt` blocks private areas and references the sitemap, or blocks everything when indexing is disabled.
- **Server-side rendering** gives crawlers fully rendered HTML: run `npm run build:ssr`, start `php artisan inertia:start-ssr` (e.g. under Supervisor) and set `INERTIA_SSR_ENABLED=true`. If the SSR server is unavailable, pages fall back to client-side rendering.

## Upgrade Notes (Laravel 12 → 13)
- PHP **8.4** and Node **22.13+** are now required.
- Sessions are serialized as JSON, so existing sessions are invalidated after deploying and users need to sign in again.
- Run `php artisan migrate` (adds the Cashier 16 `subscription_items` meter columns) and `php artisan content:sanitize`.
- Stripe: Cashier 16 uses Stripe API version `2025-07-30.basil`. Review the [Cashier upgrade guide](https://github.com/laravel/cashier-stripe/blob/16.x/UPGRADE.md) if you use metered billing or coupons.
- Frontend: shadcn-vue components now use Reka UI. Checkbox and Switch bind with `v-model` / `:model-value` (not `v-model:checked`), icons are imported from `@lucide/vue`, and Tailwind is configured in `resources/css/app.css` (there is no `tailwind.config.js`).

## HTTP API & Swagger Docs
- **Versioned endpoints** live under `/api/v1`. Public consumers can fetch published blog posts and forum threads, while
  authenticated clients may create personal access tokens and retrieve their profile details.
- **Token management**: exchange valid web credentials for a Sanctum token using `POST /api/v1/auth/token`, then include the
  resulting bearer token in the `Authorization` header for protected routes (`GET /api/v1/profile`, `DELETE /api/v1/auth/token`).
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
- **Queues & Background Work**: `composer dev` also starts `queue:listen` so job dispatches from forum moderation or notifications run instantly during development.

## Feature Notes & Endpoints
- **Forum moderation routes** handle publishing, locking, pinning, reporting, and deletion, guarded by role middleware (`role:admin|editor|moderator`).
- **Support ticket routes** provide authenticated creation, viewing, and messaging flows for end-users. Ticket creation in both
  the public portal and Admin Control Panel automatically runs through the Support Assignment Rules engine.
- **Blog previews** use signed tokens so editors can review drafts before publishing.
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
  `php artisan schedule:work` during development) alongside the queue worker so SLA adjustments happen continuously.
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
- **Stripe credentials**: Populate `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` in `.env`. Map each
  `subscription_plans` record to the correct Stripe Price ID via `config/billing.php` (or a seeder override) so subscriptions
  bill against real products.
- **Stripe CLI**: When running `stripe listen` locally, export the printed signing secret to `STRIPE_CLI_WEBHOOK_SECRET`
  (or append it to a comma-separated `STRIPE_WEBHOOK_SECRET`). The webhook handler checks both values so you can keep
  your production secret alongside the ephemeral CLI secret without editing `.env` between sessions.
- **Payment collection**: `/settings/billing` renders Stripe's Payment Element. Users create PaymentMethods client-side and the
  backend finalises subscriptions through Cashier's `newSubscription()->create()` API, including SCA flows. No plain text
  payment method IDs are accepted.
- **Webhooks**: Stripe should forward events (e.g., `invoice.payment_succeeded`, `customer.subscription.deleted`) to
  `/stripe/webhook`. Use the Stripe CLI during development:

  ```bash
  stripe listen --forward-to http://localhost:8000/stripe/webhook \
    --events invoice.payment_succeeded,invoice.payment_failed,customer.subscription.deleted
  ```

  Webhook payloads are persisted to `billing_webhook_calls` for auditing, and the handler reconciles invoices in
  `billing_invoices`.
- **Testing fixtures**: Sample webhook JSON lives in `tests/Fixtures/stripe/`. Feature tests under
  `tests/Feature/Webhooks/StripeWebhookTest.php` exercise invoice and subscription webhook flows. Use Stripe's test keys when
  exercising end-to-end subscription flows locally.
- **Queues & jobs**: Webhook handling and subscription updates rely on queued jobs. Ensure `queue:listen` (or a Supervisor job)
  is running in development and production so invoice syncing happens promptly.

## Testing & Quality
- **PHPUnit**: `composer test` (or `php artisan test`)
- **Static Analysis & Formatting**:
  - Lint Vue/TypeScript: `npm run lint` (fix) / `npm run lint:check`
  - Type-check Vue/TypeScript: `npm run types:check`
  - Check formatting: `npm run format:check` / format: `npm run format`
  - PHP style: `./vendor/bin/pint` (fix) / `./vendor/bin/pint --test`

  CI (`.github/workflows`) runs the test suite, dependency audits, Pint, Prettier and ESLint on every push and pull request.

## Production & SSR Builds
- **Frontend build**: `npm run build` outputs versioned assets for Laravel's Vite integration.
- **SSR build**: `npm run build:ssr` compiles the SPA and SSR bundle; run `php artisan inertia:start-ssr` and set `INERTIA_SSR_ENABLED=true`. Combine with `composer dev:ssr` when testing server rendering locally.
- **Env hardening**: Configure HTTPS (`SESSION_SECURE_COOKIE=true`, `APP_FORCE_HTTPS` behind a proxy), queues (e.g., Redis), the scheduler and mail drivers in `.env` before deploying. Run `php artisan optimize` during deployment.

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

## Additional Tips

- **Layout Height Utilities**: `html`, `body`, and `#app` are set to `height: 100%` in `resources/views/app.blade.php` so flex layouts and `h-full` panels render as expected across the SPA.
- **N+1 queries**: In the `local` environment lazy loading is detected and logged as a warning (`storage/logs`), so missing eager loads show up during development.
- **Storage Symlink**: Run `php artisan storage:link` after provisioning to expose public uploads (for example blog cover images) served from `storage/app/public`. Support-ticket attachments are private: they live on the disk named by `SUPPORT_ATTACHMENT_DISK` (default `local`) and are only served through an authorised download route.
- **Keep Docs Current**: When introducing new tooling, scripts, or workflows, update this README so onboarding remains frictionless for future contributors.

## License
This project is open-sourced under the [MIT License](LICENSE.md).
