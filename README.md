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
   If you plan to enable malware scanning for support attachments, review the
   file scanner configuration in this step as well.
3. **Database**
   ```bash
   php artisan migrate
   ```
   The migration set creates the `support_assignment_rules` and `support_ticket_audits` tables used by the support
   auto-assignment and auditing features.
4. **Seed Demo Content (optional)**
   ```bash
   php artisan db:seed --class=ForumDemoSeeder
   ```
   The seeder resets forum tables, creates realistic categories/boards, and populates multi-page threads for pagination testing.
5. **Run the App**
   - Start Laravel: `php artisan serve`
   - Start Vite dev server: `npm run dev`
   - Or run everything (Laravel, queues, and Vite) in one terminal: `composer dev`

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

## Attachment Malware Scanning

Support ticket uploads pass through a configurable scanning pipeline before the
files are persisted. The defaults ship with scanning disabled so local
development is frictionless, but production deployments should enable a real
scanner and quarantine storage location.

### Configuration Overview

- `config/filescanner.php` controls the default driver and quarantine disk.
- `.env` variables:
  - `FILE_SCANNER_DRIVER`: set to the driver key that should handle scans.
  - `FILE_SCANNER_QUARANTINE_DISK`: storage disk used when quarantining blocked
    files.
- The framework container resolves `App\Support\FileScanning\FileScanner` to
  the driver implementation. The default `null` driver always reports files as
  clean, so change this binding when you wire up a real scanner.

### Driver Implementation Options

Register a service provider binding for a concrete driver that implements the
`FileScanner` interface. Popular approaches include:

1. **ClamAV (Self-Hosted)**
   - Install the ClamAV daemon (`clamd`) on your infrastructure.
   - Use an adapter package such as [`sunspikes/clamav-validator`](https://github.com/sunspikes/clamav-validator) or write a
     thin driver that connects to the `clamd` socket/CLI and maps the response
     to `FileScanResult`.
   - Set `FILE_SCANNER_DRIVER=clamav` (or any custom name) and update the
     container binding in `AppServiceProvider` to resolve the new driver when
     that key is requested.

2. **Cloud Malware Scanning Services**
   - AWS (Macie/GuardDuty), Azure Defender for Storage, and Google Cloud Storage
     Threat Detection can scan uploaded objects. Your driver can stream the
     incoming attachment to the provider, poll for the verdict, and return the
     result before persisting the file locally.
   - Alternatively, SaaS APIs such as Cloudmersive or OPSWAT MetaDefender offer
     REST endpoints for on-demand scans. Use an HTTP client inside the driver to
     submit the file and translate the response payload into a
     `FileScanResult`.

Whichever option you choose, ensure blocked files are not stored on the public
disk and that friendly error messages are surfaced to end users. Feature tests
under `tests/Feature/Support/SupportTicketAttachmentScanningTest.php` cover the
expected behaviour for both clean and quarantined uploads.

## Feature Notes & Endpoints
- **Forum moderation routes** handle publishing, locking, pinning, reporting, and deletion, guarded by role middleware (`role:admin|editor|moderator`).
- **Support ticket routes** provide authenticated creation, viewing, and messaging flows for end-users. Ticket creation in both
  the public portal and Admin Control Panel automatically runs through the Support Assignment Rules engine.
- **Blog previews** use signed tokens so editors can review drafts before publishing.

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
