# Developing on your own machine

Running the site while you work on it: the ways to start it, the backing services, the commands you will use, and a few things about the code that save time. To set a site up in the first place, see [Installing](install.md); to put one on a server, see [Deploying](deployment.md); to send a change back, see [CONTRIBUTING.md](../CONTRIBUTING.md).

> **Status:** current for `main`. The commands were checked against `composer.json`, `package.json` and `php artisan list`.

## Starting the site

After the install (`php artisan metaforger:install`, then `npm run build` once), one command runs everything:

```bash
composer dev
```

It starts four processes side by side, named in the output: `server` (`php artisan serve`), `queue` (`php artisan metaforger:queue listen --tries=1`), `scheduler` (`php artisan schedule:work`) and `vite` (`npm run dev`, with hot reloading). `composer dev:ssr` builds the server-rendering bundle first and runs the SSR server and a log tail (`pail`) instead of Vite ([Search engines and sharing](seo.md#rendering-on-the-server)).

The same four, one to a terminal:

```bash
php artisan serve
php artisan metaforger:queue listen --tries=1
php artisan schedule:work
npm run dev
```

Under **Laravel Herd** or **Valet** the web server is already running at your `.test` address, so start only the last three. Use that address as `APP_URL`, or `http://localhost:8000` for `php artisan serve`.

**Why a queue worker and a scheduler at all?** The queue sends every email except the two that Laravel sends itself, pushes browser alerts and runs the shop's background jobs. The scheduler puts the timed jobs on that queue: unpaid orders expire and release their stock (every five minutes), suspensions that have ended are lifted (every minute), support tickets are checked against their service levels (every fifteen), and the search, usage and audit tables are tidied once a day. Without them a page works but something waits.

**Why `metaforger:queue`, not `queue:work`?** The notification emails wait on their own queue, `mail`, and `queue:work` and `queue:listen` read only one queue, so a worker started with them never sends an email. `metaforger:queue listen` reads the connection's own queue, `default` and `mail`, and takes the options of `queue:listen` (`metaforger:queue work` does the same for `queue:work`). [Running on Redis](redis.md#the-queue) has the production version.

## Backing services with Docker

SQLite and the log mailer need nothing. For the engines CI uses, `compose.yaml` provides them while the application keeps running on your machine:

```bash
docker compose up -d mysql redis mailpit               # choose what you need
docker compose --profile pgsql up -d pgsql             # PostgreSQL is opt-in
docker compose --profile search up -d meilisearch      # optional; then SEARCH_DRIVER=meilisearch
```

| Service | What for | On your machine (the `FORWARD_*_PORT` settings change the ports) |
| --- | --- | --- |
| `mysql` | MySQL 8 | port 3306 |
| `pgsql` (profile `pgsql`) | PostgreSQL 16 | port 5432 |
| `redis` | The cache, queue and sessions if you switch them ([Running on Redis](redis.md)) | port 6379 |
| `mailpit` | Catches every email: set `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025` | inbox at http://localhost:8025 |
| `meilisearch` (profile `search`) | The search index ([Search index](search-index.md)) | port 7700 |

Then point `.env` at them. Unless `DB_DATABASE` and `DB_PASSWORD` are set in `.env`, the containers use the database `metaforger` and the password `password` (user `root` on MySQL, `postgres` on PostgreSQL). [CONTRIBUTING.md](../CONTRIBUTING.md#testing-on-other-databases) shows how to run the tests against each. This file is for developing; the production image has its own, [Running in Docker](docker.md).

## Where things are

[Architecture](architecture.md#where-the-code-is) lists every folder and what lives in it, and how a request travels through the middleware. For a new feature, [Extending MetaForger](extending.md) says where each kind of thing goes.

## Working on the code

- **The browser app.** `resources/js/app.ts` registers Inertia, Ziggy and the progress bar and starts the theme; `resources/js/ssr.ts` mirrors it for server rendering and exposes the routes, so `route()` works there too.
- **Light and dark.** `useAppearance()` keeps the choice in `localStorage` and a cookie, so the server and the browser agree:

  ```ts
  const { appearance, updateAppearance } = useAppearance();
  updateAppearance('dark');
  ```

  Use the colour tokens (`bg-card`, `text-muted-foreground` and so on) and not raw colours ([Theming](theming.md)).
- **Roles and permissions in the interface.** Guard what is shown with the composables; the server still decides what is allowed:

  ```ts
  const { hasRole } = useRoles();
  const { hasPermission } = usePermissions();

  const isAdmin = computed(() => hasRole('admin|super-admin'));
  const canManageUsers = computed(() => hasPermission('users.acp.manage'));
  ```
- **Words.** New interface text goes through `useI18n()` (`t()` and `tc()`) with its keys in `lang/en` ([Internationalisation](i18n.md)). The shop's pages still use literal English.
- **Queries.** In the `local` environment a relation that is loaded lazily is logged as a warning in `storage/logs`, and in the tests it is an error: load what a list shows for the whole list ([Query budgets](query-budgets.md)).
- **Layout height.** `html`, `body` and `#app` are `height: 100%` in `resources/views/app.blade.php`, so flex layouts and `h-full` panels fill the window.
- **Uploads.** `php artisan storage:link` exposes `storage/app/public` (product pictures, blog covers). Support attachments, forum files and digital downloads are on private disks and are only served through authorised routes ([Private uploads](private-uploads.md)).

## Addresses worth knowing

| Address | What is there |
| --- | --- |
| `/shop`, `/cart`, `/checkout`, `/orders` | The storefront, the cart, checkout and a customer's order history. `/settings/addresses` is the address book. A file is delivered from `/downloads/{grant}/{file}` as a signed, rate-limited link |
| `/settings/billing`, `/settings/security` | Plans, payment methods and invoices; two-factor, sessions and linked accounts |
| `/acp/commerce`, `/acp/billing/invoices`, `/acp/support/…` | The shop, the invoice browser and the support desk in the admin area |
| `/api/docs`, `/api/docs/openapi.json` | Swagger UI and the schema it reads ([HTTP API](api.md)) |
| `/stripe/webhook`, `/broadcasting/auth` | Where Stripe sends its events, and where the browser asks to join a live channel ([Payments](payments.md), [Live updates](realtime.md)) |
| `/sitemap.xml`, `/robots.txt` | Generated; see [Search engines and sharing](seo.md) |

## Commands

| Command | What it does |
|---------|--------------|
| `php artisan metaforger:install` | Set up a new site: check the machine, make `.env` and the key, create the tables, link storage, add the roles, categories and badges, make the first administrator. Safe to run again; `--demo` adds demo data (never in production); `--legal-pages` adds a starting text for the legal pages. See [Installing](install.md). |
| `composer setup` | First-time setup of the code: install dependencies, create `.env`, generate the key, migrate and build assets. Follow it with `metaforger:install`. |
| `composer dev` / `composer dev:ssr` | Run the server, queue worker, scheduler and Vite (the SSR variant builds SSR first and adds log tailing and the SSR server). |
| `php artisan metaforger:queue listen` / `work` | A queue worker that also reads the `mail` queue. |
| `php artisan commerce:expire-orders` | Cancel unpaid orders past their payment window and release their stock (the scheduler runs this every five minutes). |
| `php artisan tebex:sync` | Refresh the shop's copy of a Tebex store ([Tebex](tebex.md)). |
| `php artisan metaforger:search:sync` / `reindex` / `health` | Apply the Meilisearch settings, rebuild its documents, and check that it is reachable and holds what it should ([Search index](search-index.md)). |
| `php artisan lang:check` | Fail if a language is missing keys the fallback language has. |
| `php artisan api:docs` | Regenerate the OpenAPI schema used by Swagger UI. |
| `php artisan content:sanitize [--dry-run]` | Re-sanitise stored forum and blog HTML. |
| `php artisan support:attachments:privatize [--dry-run]` | Move support attachments from the public disk to the private one. |
| `php artisan metaforger:blog:covers:upgrade` | Draw blog covers again at every size ([Pictures](images.md)). |

Checking your work (`vendor/bin/pint`, `composer analyse`, `php artisan test`, `npm run lint:check`, `npm run types:check`, `npm run format:check` and the rest) is in [CONTRIBUTING.md](../CONTRIBUTING.md#checks). Tests use an in-memory SQLite database whatever `.env` says, so they never touch your data.

## Tools used

[Laravel](https://laravel.com/docs/13.x), [Vue](https://vuejs.org/guide/quick-start.html), [Inertia](https://inertiajs.com/docs/v3/getting-started/index), the [Vue starter kit](https://laravel.com/docs/13.x/starter-kits#vue), [Tailwind CSS](https://tailwindcss.com/docs), [shadcn-vue](https://www.shadcn-vue.com/), [Lucide icons](https://lucide.dev/icons/), [Vue Sonner](https://vue-sonner.vercel.app/), [Tiptap](https://tiptap.dev/docs/editor/getting-started/install/vue3) and the [Stripe CLI](https://docs.stripe.com/stripe-cli).
