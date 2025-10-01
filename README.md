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
- **Support Center**: Ticket submission, messaging threads, and authenticated access to customer conversations.
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

## Feature Notes & Endpoints
- **Forum moderation routes** handle publishing, locking, pinning, reporting, and deletion, guarded by role middleware (`role:admin|editor|moderator`).
- **Support ticket routes** provide authenticated creation, viewing, and messaging flows for end-users.
- **Blog previews** use signed tokens so editors can review drafts before publishing.

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
