# Laravel Vue Starter

A batteries-included starter kit for building modern Laravel + Vue single-page applications. The project ships with a production-ready forum, blog, support center, and admin tooling so teams can focus on features rather than scaffolding. Inertia.js keeps the frontend and backend in sync, Tailwind CSS powers the design system, and first-class TypeScript support ensures maintainable UI code.

![Forum Page Example](https://i.imgur.com/gYNFkFl.png)

## Stack Highlights
- **Backend – Laravel 12** with Sanctum for API tokens, Spatie Permissions for RBAC, queue/listener scaffolding, and opinionated seeders for fast iteration.
- **Frontend – Vue 3 + Inertia.js + TypeScript** with Ziggy-powered routing, SSR entry points, and theme initialization in a single SPA shell.
- **UI & Editor Toolkit – Tailwind CSS, shadcn-inspired components, Radix Vue primitives, Vue Sonner toasts, and Tiptap rich text editing for forum and blog content workflows.
- **Data Visualization – Unovis (VisX for Vue) for charts and dashboards inside the admin area.
- **Developer Experience – Vite 6, ESLint + Prettier, Pint, and convenience scripts for running Laravel, queues, SSR, and Vite together.

## Application Modules
- **Forum System**: Boards, threads, post moderation, publishing workflows, and tracking read state with dedicated controllers and routes.
- **Blog & Previewing**: Public blog listing, tokenized preview links, and authenticated commenting APIs.
- **Support Center**: Ticket submission, messaging threads, authenticated access to customer conversations, and configurable
  assignment rules so tickets auto-route to the right agents.
- **Billing & Subscriptions**: Stripe-powered subscriptions via Laravel Cashier, an end-user settings page for plan management, and an admin invoice browser with webhook visibility.
- **Admin Control Panel (ACP)**: Inertia-powered layouts under `resources/js/pages/acp` for managing users, forums, and content. Permission middleware ensures only privileged roles can reach moderation endpoints.
- **Authentication & Authorization**: Laravel Breeze for authentication plus Spatie role/permission gating surfaced to the SPA via dedicated composables.
- **Appearance Management**: System/light/dark modes synced between SSR and the client through a reusable composable.

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
- PHP 8.2+ with Composer.
- Node.js 20+ (LTS recommended) with npm or pnpm for frontend tooling.
- A database supported by Laravel (MySQL/MariaDB or PostgreSQL work out of the box). Configure credentials in `.env`.

## Quick Start
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
- **Assignment Rules**: Configure agent routing with ordered rules stored in the `support_assignment_rules` table. Rules can
  target specific categories or priorities and are evaluated top-to-bottom until a match assigns the ticket.
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
...     'assigned_to' => 5,                // required user id
...     'position' => 10,
... ]);
```
Rules with lower `position` values are evaluated first, so place the most specific rules near the top of the list.

### Billing & Subscription Management
- **Stripe credentials**: Populate `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` in `.env`. Map each
  `subscription_plans` record to the correct Stripe Price ID via `config/billing.php` (or a seeder override) so subscriptions
  bill against real products.
- **Cashier installation**: The application now depends on the official `laravel/cashier` package. Run `composer install` and
  publish the vendor migrations via `php artisan vendor:publish --tag="cashier-migrations"` followed by
  `php artisan migrate` to ensure Cashier's tables (`subscriptions`, `subscription_items`, etc.) are present.
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
- **PHPUnit**: `php artisan test` or `./vendor/bin/phpunit`
- **Static Analysis & Formatting**:
  - Lint Vue/TypeScript: `npm run lint`
  - Check formatting: `npm run format:check`
  - Fix PHP style: `./vendor/bin/pint`
  - Format Vue/TS: `npm run format`
  These commands are pre-configured via npm and Composer scripts for consistent CI enforcement.

## Production & SSR Builds
- **Frontend build**: `npm run build` outputs versioned assets for Laravel's Vite integration.
- **SSR build**: `npm run build:ssr` compiles the SPA and SSR bundle; combine with `composer dev:ssr` when testing server rendering locally.
- **Env hardening**: Remember to configure HTTPS, queues (e.g., Redis), and mail drivers in `.env` before deploying.

## Contributing
Issues and pull requests are welcome! Please include tests or updates to this documentation when modifying setup steps, tooling, or major features.

## Useful Links

- [Laravel Documentation](https://laravel.com/docs/12.x)
- [Vue.js Documentation](https://vuejs.org/guide/quick-start.html)
- [Inertia.js Documentation](https://inertiajs.com/)
- [Laravel Starter Kits](https://laravel.com/docs/12.x/starter-kits#vue)
- [TailwindCSS Documentation](https://tailwindcss.com/docs/dark-mode)
- [shadcn-vue Component Library](https://www.shadcn-vue.com/)
- [Lucide Icons](https://lucide.dev/icons/)
- [Vue Sonner Toast Component](https://vue-sonner.vercel.app/)
- [Tiptap Editor](https://tiptap.dev/docs/editor/getting-started/install/vue3)

## Additional Tips

- **Layout Height Utilities**: Ensure `html`, `body`, and `#app` are set to `height: 100%` (or wrap your root layout in `min-h-screen`) so flex layouts and `h-full` panels render as expected across the SPA.
- **Storage Symlink**: Run `php artisan storage:link` after provisioning to expose public asset uploads (e.g., avatars, attachments) served from `storage/app/public`.
- **Keep Docs Current**: When introducing new tooling, scripts, or workflows, update this README so onboarding remains frictionless for future contributors.

## License
This project is open-sourced under the [MIT License](LICENSE.md).
