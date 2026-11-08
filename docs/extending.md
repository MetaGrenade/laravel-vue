# Extending MetaForger

How to add your own features to a site built on MetaForger: where each kind of thing goes, which page already has the recipe, and which test tells you when you forgot something. If you only want to change the look, see [theming.md](theming.md); for another language, [adding-a-language.md](adding-a-language.md).

> **Status:** written from the code, by searching for every place the existing areas plug in. Nothing here was built as a trial module, so the list for a new switchable section ([below](#a-new-section-that-can-be-switched-off)) is what the code showed, not something proven by adding one. The recipes that already had their own page are linked, not repeated.

## There is no plug-in system

A "module" in MetaForger is an **area of the app that is written the way the others are**: routes, controllers, models, Inertia pages and language keys, added beside the existing ones. There is no package loader, no registry a third-party package adds itself to, and no hooks to subscribe to, except Laravel's own (events and listeners, policies, middleware). Four areas can be **switched off** in the admin area: the blog, the forum, the support centre and the shop. Everything else is always on.

That has two consequences. Adding a feature means editing a few files that list the existing ones (the lists are named below), and the more you add as **new files** rather than changes to old ones, the easier it is to take the next MetaForger release ([upgrading](#keeping-it-upgradeable)).

## Where things live

| What | Where |
|---|---|
| Routes | `routes/web.php` (public and signed-in), `routes/auth.php`, `routes/settings.php`, `routes/admin.php` (every address under `acp/`, every name `acp.*`), `routes/api.php` |
| Controllers | `app/Http/Controllers`, with `Admin/`, `Settings/`, `Ecommerce/`, `Api/`, `Webhooks/` |
| What the controllers call | `app/Support/<Area>` (for example `Commerce`, `Billing`, `Entitlements`, `Search`, `Seo`), and `app/Payments` for providers |
| Models, policies, jobs, notifications, listeners | `app/Models`, `app/Policies`, `app/Jobs`, `app/Notifications`, `app/Listeners` |
| Pages | `resources/js/pages/<Area>/<Page>.vue` (what a controller renders with `Inertia::render('Area/Page')`), the admin area in `pages/acp` |
| Layouts and menus | `resources/js/layouts` (the admin menu is `layouts/acp/AdminLayout.vue`; the header's is `components/AppHeader.vue`) |
| Words | `lang/en/<group>.php` |
| Settings | `config/*.php`, `.env.example`, a row in [configuration.md](configuration.md) |
| Scheduled work | `bootstrap/app.php` (`$schedule->job(...)`) |
| Tests | `tests/Feature/<Area>` |

## A new section that can be switched off

The four sections are `blog`, `forum`, `support` and `commerce`. A section off means its routes answer 404, its links disappear, and it drops out of search, the sitemap and the dashboard. The payments webhooks, a customer's receipt and a purchased file's download link stay reachable on purpose (they sit outside the `section.enabled:commerce` group in `routes/web.php`), so a payment in flight still settles and a buyer keeps what they bought. To add a fifth, these are the places that name the existing four (found by searching the code for `WebsiteSections`):

1. **`app/Support/WebsiteSections.php`:** a constant and an entry in `defaults()`. A site that has already stored its choices gets the new key at its default, because the stored settings are merged over the defaults.
2. **`resources/js/pages/acp/System.vue`:** the switches are written out one by one (the form's keys and one block each). The save request in `SystemSettingsController` is built from `WebsiteSections::keys()`, so it needs nothing.
3. **The TypeScript that names the four:** `website_sections` in `resources/js/types/index.d.ts` is `Record<'blog' | 'forum' | 'support' | 'commerce', boolean>`, and `AppHeader.vue` has the same four in the type of a menu item's `section`. Add yours or a page cannot read it without a type error. The values reach every page already (`HandleInertiaRequests` shares `WebsiteSections::all()`).
4. **Routes:** wrap them in `section.enabled:<key>` (`Route::middleware('section.enabled:docs')`), in `routes/web.php`, and in `routes/api.php`, `routes/settings.php` or `routes/admin.php` as they apply.
5. **Menus:** give the header's link `section: '<key>'` in `AppHeader.vue`, and hide the footer's in `AppFooter.vue` and the admin menu's in `AdminLayout.vue` (`can('docs.acp.view') && sections.value.docs`). All three build a small object of the four keys with `true` as the fallback (`websiteSections` in the header, `sections` in the footer and the admin layout); add yours to each, or a page that has not been sent the setting hides it.
6. **Everything that counts or lists the sections:** `SitemapController` (one block each), the dashboard, the onboarding checklist, the member directory and profile, and the privacy page. Read each if your section has something to show there.
7. **Search**, if it is searchable: global search has no registry, see [what is not open to extension](#what-is-not-open-to-extension).
8. **A test** in the manner of `tests/Feature/WebsiteSectionsTest.php`: with the section off, its routes answer 404.

## The usual jobs

Each row is a job and where its recipe is. Where there is a page, that page is the recipe.

| You want to | Do | Read |
|---|---|---|
| **Add a page** | A named route, a controller that renders an Inertia page, the page. Set its title and description with `app(Seo::class)->title(...)->description(...)`; add `->noindex()` for anything private. | [SEO settings](configuration.md#search-engines-and-social-cards) |
| **Keep it fast** | A page that lists rows gets a query budget; lazy loading fails the test suite. | [query-budgets.md](query-budgets.md#adding-a-page) |
| **Add a table** | `index()` before `constrained()` on every foreign key, or `ForeignKeyIndexTest` fails. A list shown in an order (newest first, most commented) may want a composite index for that order. | [indexes.md](indexes.md#the-rule-for-new-tables) |
| **Say who owns a record** | A table that is billable, or that a team could share, carries `owner_type` and `owner_id` and the `HasOwner` trait. Ask the policy or `visibleTo()`, not `user_id`; add the model to `OwnerResolutionTest`. | [ownership.md](ownership.md#rules-for-new-code), [record-policies.md](record-policies.md) |
| **Decide who may see or change it** | A policy in `app/Policies` (Laravel finds `OrderPolicy` for `Order`) and `can:` or `->authorize()` on the route. | [record-policies.md](record-policies.md) |
| **Add an admin area** | Routes in `routes/admin.php` with `can:<area>.acp.view`; add `<area>` to the list in `database/seeders/RolePermissionSeeder.php` (it makes `view`, `create`, `edit` and `delete`; extra permissions are added by hand in the same file); a menu entry in `AdminLayout.vue`. | [acp-permissions.md](acp-permissions.md) |
| **Record what staff changed** | Nothing, for who did what, to what and from where: `AuditAdminActions` looks at the address, so a change made under `acp/` is written to the audit log whatever its route group (a test checks every admin write route is covered). To also record what a model was and what it became, add the model to `models` in `config/audit.php`. | [audit-log.md](audit-log.md) |
| **Add an API endpoint** | In `routes/api.php`, with a scope (`scope:your:scope`) in `config/api.php`; describe it in `app/Support/OpenApi/Specification.php`; add its row to the table in `docs/api.md`; then `php artisan api:docs`. | [api-keys.md](api-keys.md#adding-a-scope), [api.md](api.md#every-endpoint) |
| **Send an email or a notice** | A notification with `MailMessage` (never `->view()`), every sentence a key of `lang/en/mail.php`, written in the recipient's language. | [email.md](email.md#writing-a-notification), [i18n.md](i18n.md#emails) |
| **Add words to a screen** | A key in `lang/en`, then `t('group.key')` in Vue or `__('group.key')` in PHP. | [i18n.md](i18n.md#adding-strings) |
| **Add a setting** | Read `env()` only in `config/*.php`, put the key in `.env.example` so the next person finds it, and add a row to `docs/configuration.md` (`ConfigurationDocsTest` fails without it). | [CONTRIBUTING.md](../CONTRIBUTING.md#checks) |
| **Run something on a schedule** | `$schedule->job(...)` in `bootstrap/app.php`. It only puts a job on the queue, so a queue worker has to be running. | [deployment.md](deployment.md#what-a-live-site-is-made-of) |
| **Take payment another way** | Implement the `PaymentProvider` contract, register it under `commerce.providers`, and route its webhooks through `WebhookReceiver`. | [commerce.md](commerce.md#payment-providers), [payments.md](payments.md) |
| **Give a customer something for a purchase or a plan** | A feature or a limit is data (**Commerce → Entitlements**); ask for it with `$user->canUse('x')`, the `entitled` route middleware or `useEntitlements()`. | [entitlements.md](entitlements.md) |
| **Add a step to the "get started" checklist** | Its key in `config/onboarding.php`, a line in `Onboarding::step()`, its words in `lang/en/ui.php`, a test. | [onboarding.md](onboarding.md#adding-a-step) |
| **Add an error status or change its words** | The status in `ErrorPages::STATUSES` and two sentences in each `lang/<locale>/errors.php`. | [error-pages.md](error-pages.md#adding-a-status-or-changing-the-words) |
| **Serve a file that is the same for everyone with caching** | `PublicDocuments::cachedFor($seconds)` on the route, and a line in `PublicDocumentsTest::documents()`. | [http-caching.md](http-caching.md#adding-a-document) |
| **Use a script, a frame or a font from another host** | Name the host in `config/security.php`, report-only first. | [security-headers.md](security-headers.md#adding-a-third-party-host) |
| **Add a dependency, a font or an image** | The licence policy decides; `npm run licences:check` fails for one it does not allow. | [licences.md](licences.md#adding-a-dependency) |
| **Hold personal data about a person** | Decide what happens to it when the account is deleted (the existing tables use `cascadeOnDelete()` on `user_id`; the audit log keeps its rows on purpose) and add it to the data export in `GenerateUserDataExport::buildPayload()`. Neither happens by itself. | [private-uploads.md](private-uploads.md) for how an export is stored and downloaded; [audit-log.md](audit-log.md) for the one record that is not erased |
| **Keep it safe on Octane and queue workers** | `scoped` bindings, no static state that grows, nothing request-shaped captured by a closure. | [octane.md](octane.md#rules-for-new-code) |

A few rules the table does not repeat:

- **Name every route you want Vue to link to.** The browser only knows the named routes it is sent (`route('name')`), and a visitor who is not staff is not sent `acp.*`. Do not give a public page an `acp.` name.
- **Never settle an order because a buyer came back.** An order is settled by the provider's webhook, or by asking the provider what happened (`reconcile`, which the return page does when the webhook is late). The address a buyer returns to proves nothing ([payments.md](payments.md)).
- **Write `user_id` to record who acted, not to decide who may see.** On a record an account pays for or a team could share, that decision goes through the policy ([ownership.md](ownership.md#rules-for-new-code)).
- **A word on a screen is a key.** `vue/no-bare-strings-in-template` warns about English in the Vue files that have been converted; it lists them by name in `eslint.config.js`, so add a file there when you convert it (and your own new files, if you want the warning).

## The tests that tell you what you forgot

You do not have to remember all of the above: much of it fails a check. Run them as the project does ([CONTRIBUTING.md](../CONTRIBUTING.md#checks) has the commands).

| Check | Fails when |
|---|---|
| `ConfigurationDocsTest` | a setting read in `config/` has no row in `configuration.md`, a row has nothing behind it, or a default written as code is not the real one |
| `TranslationKeysTest` | a key the Vue pages use is not in `lang/en`, or a group they read is not sent to the page |
| `ServerTranslationKeysTest` | a key the PHP or Blade code uses is not in `lang/en`, or is not given the values it asks for |
| `php artisan lang:check` | a language is missing a key that English has |
| `MailLanguageTest`, `BrandedMailTest` | an email is not in the recipient's language, or a notification builds a layout of its own or an `app/Mail` folder appears |
| `ForeignKeyIndexTest` | a foreign key has no index starting with it |
| `LazyLoadingModeTest` | the test suite runs with lazy loading forbidden, so a relation read for each row of a list fails |
| `QueryBudgetTest` | one of the pages it lists asks for more queries than its ceiling (add yours to it) |
| `OctaneSafetyTest` | `app/` gains a static property or a binding kept for the life of the process that the test does not list with a reason |
| `ZiggyRouteSyncTest` | the route map a person is sent stops matching who they are (staff or not, signed in or out) |
| `AuditLogTest` | an admin route that changes something is not covered by the audit middleware, an entry is changed or deleted, or one carries the values that were sent |
| `OwnerResolutionTest` | a record opens for someone who is not its owner (or not acting for it), or stays shut to one who is |
| `OpenApiScopesTest`, `TokenScopesTest` | an API route that takes a key asks for no scope, asks for one the site does not have, or the API document and the routes disagree |
| `ApiReferenceDocsTest` | an API route has no row in [api.md](api.md), a row has no route, a row names a scope or a section that is not the route's, or the OpenAPI document does not describe exactly the `/api/v1` routes |
| `npm run docs:check` | a link or heading anchor in a Markdown page is broken |
| `npm run bundle:check` | the JavaScript a page loads is over its budget ([bundle-budget.md](bundle-budget.md)) |
| `npm run licences:check` | a dependency's licence is not allowed |
| The secrets scan (CI) | a key or a token is committed ([secrets-scan.md](secrets-scan.md)) |
| `vendor/bin/pint --test`, `composer analyse` | the PHP style, or the static analysis, which also has a baseline that is meant only to shrink |

## What is not open to extension

These are fixed lists in the code, with no registry. They are the places an extension is an edit, not an addition:

- **Global search is three groups** (`blogs`, `forum_threads`, `faqs`). `GlobalSearchService` lists them (`GROUPS`), has a search for each, a second one for each in `MeilisearchGlobalSearch` for a site that uses Meilisearch, and says which section each belongs to; the results page (`pages/Search/Results.vue`) and the command palette name them too. A fourth group touches all of those. [search-index.md](search-index.md) says what the index holds.
- **The sections** are the four above ([A new section](#a-new-section-that-can-be-switched-off)).
- **Notification preferences and a digest's areas** come from `config/notification-preferences.php`, in English.
- **Roles.** `admin`, `editor`, `moderator` and `user` are made by `RolePermissionSeeder`, and "staff" is a list written out in more than one place: `ZiggyRouteGroup::STAFF_ROLES` (who is sent the admin routes; also who the automod does not check, who only an administrator can moderate, and who cannot be impersonated) and `roles: 'admin|editor|moderator'` on the header's admin link. A fourth staff role means finding each use of both.
- **The homepage and the footer** are ordinary files you edit ([theming.md](theming.md#words-and-links)).

## Keeping it upgradeable

MetaForger will keep changing, and your copy is a fork. What makes taking its releases cheaper:

- **New files over edited ones.** Put your area in its own `app/Support/<Area>`, its own controllers and pages, and its own `lang/en/<group>.php`. The files that list areas (the ones above) are where a merge conflict will be, so keep your edit to each of them small.
- **Keep the checks green** and add your own tests next to the area's. A release that changes a rule you build on fails your tests rather than your customers.
- **Read the changelog** for the version before you merge it ([CHANGELOG.md](../CHANGELOG.md)); a release that changes a table, a permission or a setting says so under its version.
- **Seeders.** A new permission exists only after `php artisan db:seed --class=RolePermissionSeeder` has run on the site, and that seeder also gives the `admin` role every permission that exists.
