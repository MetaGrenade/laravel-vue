# Upgrading

How to move a site that is already running to a newer version of MetaForger without losing what is in it, and what to look at afterwards.

> **Status:** written on 8 November 2026, before any release has been tagged. Until 1.0.0 there is no tag to move between: a site moves from one commit of `main` to a later one, and the steps are the same. From 1.0.0 the CHANGELOG has a section for each release, and a release that needs more than [the usual deploy](deployment.md#every-deploy) says so under an *Upgrading* heading. [What was tried](#what-was-tried) says what has been run and what has not.

## The short version

1. **Back up** the database, `.env` and `storage/app` ([what to back up](deployment.md#files-mail-and-backups)).
2. **Read** the CHANGELOG from the version you run to the one you move to, and [what changes for the people who run the site](#what-changes-for-the-people-who-run-the-site).
3. **Rehearse** on a copy of the database if the site matters ([how](#rehearse-on-a-copy)).
4. **Deploy** with the list in [Every deploy](deployment.md#every-deploy). That list already runs the migrations.
5. **Once, after the migrations**, run the steps in [Once, after the migrations](#once-after-the-migrations) that apply to you. None of them is done for you.
6. **Check** with [Checking a deploy](deployment.md#checking-a-deploy) and [Checking that it worked](#checking-that-it-worked).

## Before you start

### Know where you are

There is no version number to read yet. The commit is the version:

```bash
git log -1 --format='%h %cd' --date=short
```

With the new code in place and `composer install` run, this lists what the upgrade is going to change in the database, and changes nothing:

```bash
php artisan migrate:status --pending
```

`php artisan migrate --pretend` prints the SQL of those migrations without running it. An empty list means the database does not change.

### The machine

- **PHP 8.4 or newer** (`composer.json`). Composer refuses to install on an older PHP, and the command line and PHP-FPM can be two different versions: check both.
- **Node.js 22.13 or newer** (`.nvmrc` names 24) wherever you run `npm run build`. A server that only receives the built `public/build` folder needs no Node, unless it serves [server-side rendering](deployment.md#server-side-rendering-optional).
- The PHP extensions in the README's [Prerequisites](../README.md#prerequisites).

### Compare `.env` with `.env.example`

A setting your `.env` does not have takes its default from `config/`, and [configuration.md](configuration.md) lists the default of every one. Reading the settings that are new to your file is still worth ten minutes:

```bash
comm -13 <(grep -oE '^[A-Z][A-Z0-9_]*' .env | sort -u) <(grep -oE '^[A-Z][A-Z0-9_]*' .env.example | sort -u)
```

That prints the names `.env.example` sets and your `.env` does not. Most of them are optional. The ones to look at first are in [the table below](#what-changes-for-the-people-who-run-the-site).

### Rehearse on a copy

Running the upgrade on a copy of the live database costs some of your time and shows what the live run is going to do: how long the migrations take, what the seeder and the commands report, and what the site looks like afterwards.

1. Restore your backup of the database into a **second** database, and your `storage/app` next to it. The move of support attachments works on those files; without them a rehearsal reports every attachment as missing.
2. Run the upgrade from a **separate checkout** of the code with its own `.env` that names the second database, on another machine or another folder. Not from the live checkout: a cached configuration (`bootstrap/cache/config.php`) ignores environment variables given on the command line, and `DB_URL`, when it is set, wins over `DB_DATABASE`. Either one can send `migrate` to the live database.
3. Before the first command, run `php artisan db:show` there and read the database it names.
4. Follow this page there, then sign in and click through it.

## The deploy

[Every deploy](deployment.md#every-deploy) is the whole procedure: check out the new code, install, build, `php artisan migrate --force`, `php artisan api:docs`, `php artisan optimize`, `php artisan queue:restart`. For an upgrade that spans several releases:

- **Put the site in maintenance mode** for the migrations (`php artisan down --refresh=15`, then `php artisan up`). While they run, the old code does not always work with the new tables, and the new code does not always work with the old ones.
- **Do not run `composer update`** on the server. `composer install` installs exactly the versions `composer.lock` names, which are the ones the tests ran against.
- **Never `php artisan db:seed`** without `--class` on a live site. The default seeder adds a demo account with a known password.
- **The migrations of `metagrenade/metaforge-discord`** (the Discord companion, a separate package) run with the rest: eight tables whose names start with `discord_`. A site that does not use Discord gets them empty and can ignore them ([discord-companion.md](discord-companion.md)).
- **On a large live PostgreSQL database**, two migrations build indexes and hold a write lock on their table while they do. Read [Running it on a live PostgreSQL database](indexes.md#running-it-on-a-live-postgresql-database) before the deploy and build the big ones by hand first.

## Once, after the migrations

The commands in this order, each explained below. Leave out what does not apply.

```bash
php artisan storage:link                                  # when public/storage does not exist
php artisan db:seed --class=RolePermissionSeeder --force  # read "Roles and permissions" first
php artisan support:attachments:privatize --dry-run       # how many files are still on a public disk
php artisan content:sanitize --dry-run                    # how many stored posts would change
php artisan content:sanitize
```

### The storage link

Product pictures and blog covers are served from `storage/app/public` through the link `public/storage`. The installer makes it, so a site that was set up with the installer has it. Look:

```bash
ls -l public/storage
```

If it is not there, `php artisan storage:link` makes it. Without it every uploaded picture is a broken image.

### Roles and permissions

A permission that a release adds exists only once the role seeder has run on your site. The seeder is not run for you.

Administrators do not need it: they pass every permission check. Without it, though, the new permission is not in the list under **Admin → Access control**, so you cannot give it to an editor or a moderator, and the defaults below are not given. The pages for the features that add permissions say so ([audit log](audit-log.md#upgrading), [moderation](moderation.md), [impersonation](impersonation.md), [automod](automod.md)).

**It does more than add permissions**, and one part of that surprises people:

- It creates each permission that does not exist. One that exists is left alone.
- It gives the `admin` role every permission.
- It gives `editor` the three poll permissions and `moderator` the seven it is meant to have (`polls.acp.view`, `users.acp.view`, `users.acp.warn`, `users.acp.ban`, `users.acp.cleanup`, `forums.acp.view` and `forums.acp.review`). It never takes a permission away, but it **puts back** one you took away from those two roles on purpose.
- **It gives the `admin` role to the member with id 1** when that member does not have it. On a site whose first account is its owner, nothing changes. On a site where member 1 is somebody else, it makes that person an administrator. In the rehearsal member 1 was an ordinary member, and after the seeder was an administrator.

So, before you run it:

1. Look at member 1:

   ```bash
   php artisan tinker
   >>> App\Models\User::find(1)?->only('id', 'nickname', 'email');
   ```

2. Write down the permissions of `editor` and `moderator` under **Admin → Access control**, so that you can see what it put back.
3. Run it. If member 1 should not be an administrator, take the role away under **Admin → Users → Edit** afterwards. The next run of the seeder gives it back.

Run `RolePermissionSeeder` itself, not `BaselineSeeder`: the baseline also puts back support categories and badges that staff removed ([Running it again](install.md#running-it-again)).

### Support attachments

Support ticket files used to be on the public disk, where anyone with the address could fetch them. A migration moves them to the private disk (`SUPPORT_ATTACHMENT_DISK`, `local` unless you set it) as part of `migrate`. If a file cannot be moved, the migration does not fail: it writes a `critical` line to `storage/logs/laravel.log` that says how many are still reachable.

```bash
php artisan support:attachments:privatize --dry-run
```

It says how many attachments are not on the private disk. Run it without `--dry-run` to move them, and again after you fix the cause of any it could not. It reports four results: *moved*, *missing* (no file on the disk: the row is left as it is, on its old disk, and is counted again by the next run), *failed* (permissions or a disk error: the file stays where it is and **may still be public**) and *skipped*. A count that does not reach zero after a run is usually *missing* files, for example when the database was restored without `storage/app`.

### Stored text

Forum posts, blog posts and their revisions are cleaned when they are written. Text that was stored before that was so is cleaned by:

```bash
php artisan content:sanitize --dry-run     # say how many records would change
php artisan content:sanitize
```

A site whose text was all written since the cleaning began sees little or nothing to change. It rewrites only the bodies that differ from their cleaned form, so running it again changes nothing.

### Search

Only with `SEARCH_DRIVER=meilisearch`. The index is a copy of the database, so it can always be made again:

```bash
php artisan metaforger:search:health
```

If it says a count is off or the settings are not the coded ones, run `php artisan metaforger:search:sync` and then `php artisan metaforger:search:reindex` ([search-index.md](search-index.md#deploying)). Saving a record is indexed on the queue when Meilisearch is on, so a worker has to be running.

A site that had `SEARCH_DRIVER=mysql_fulltext` searches the database now: that driver was removed, and any value other than `meilisearch` means the database.

### Blog covers

A cover uploaded before the sizes were built has one file and is shown at that size. `php artisan metaforger:blog:covers:upgrade --dry-run` says how many would be drawn again and without `--dry-run` it does so ([images.md](images.md#covers-already-on-the-site)).

## What changes for the people who run the site

Behaviour that was different in an earlier build and is different now. The CHANGELOG has the reasons.

| You notice | What it is | What to do |
|---|---|---|
| **Everyone is signed out once.** | Laravel 13 reads sessions as JSON (`config/session.php`). A session the earlier build wrote in PHP's format cannot be read. | Nothing. Say so, if you can. |
| **A cron line, a Supervisor program or a deploy script fails with "command not defined".** | Six commands were renamed with the project, with no alias: `metaforger:install`, `metaforger:queue`, `metaforger:search:reindex`, `metaforger:search:health`, `metaforger:search:sync` and `metaforger:blog:covers:upgrade`. The old names are in the CHANGELOG entry *The project is renamed MetaForger*. | Find every `artisan` call in your cron, Supervisor and deploy files and check each against `php artisan list`. `docker compose up` also makes new `metaforger-*` containers and a new database, and leaves the old ones alone. |
| **Emails are not sent; rows pile up in `jobs`.** | Every notification email is queued on a queue named `mail`. A worker started with `queue:work` and no `--queue` reads only `default`. | Start the worker with `php artisan metaforger:queue work` ([the queue](redis.md#the-queue)) and `php artisan queue:restart` after the deploy. |
| **A key for the API answers `403`.** | A key's abilities used to be free text that nothing checked. They are scopes now, and a key whose abilities name none of the site's scopes (for example `read`) can call nothing that needs one. | Fix them under **Admin → Tokens** ([Upgrading from before scopes](api-keys.md#upgrading-from-before-scopes)), then `php artisan api:docs`. |
| **A client that signs in over the API gets a key that only reads the profile, and it ends after 90 days.** | It used to get full access that never ended. | Have the client ask for the scopes it needs, or keep the old behaviour with `API_SIGN_IN_SCOPES=*` and `API_SIGN_IN_EXPIRY_DAYS=0` ([sign-in defaults](api-keys.md#upgrading-from-before-rotation-and-the-sign-in-defaults)). |
| **A key that staff revoked stops working.** | The Revoke button only recorded the revocation and nothing checked it, so a revoked key went on working. | Nothing. A key you revoked for good now really is revoked; one you revoked to restore later works again when you restore it. |
| **An editor or a moderator can no longer do something in the admin area.** | Changing billing plans, replaying a webhook delivery, deleting one member and banning a member each check their own permission now: `billing.acp.create`, `.edit` and `.delete`, `users.acp.delete`, `users.acp.ban` (and a ban needs a reason). | Give the permission under **Admin → Access control** to the roles that need it. Administrators are not affected. |
| **The failed deliveries on Stripe's webhook endpoint stop.** | The webhook answered `500` to every event that Cashier handles and to every one nobody handles. It answers `200` now, and a renewal that needs the customer's bank no longer ends in an error. | Check that the endpoint receives the events the site needs ([Webhook events](billing.md#webhook-events)). `CASHIER_PAYMENT_NOTIFICATION` is unset by default, as in Cashier. |
| **Links in emails say `403 Invalid signature`, or the site sends no `Strict-Transport-Security` header.** | The site sits behind a proxy or load balancer that ends HTTPS and does not trust it. | Set `TRUSTED_PROXIES` ([Behind a proxy or a load balancer](deployment.md#behind-a-proxy-or-a-load-balancer)). |
| **Checkout asks for a shipping address.** | Products are shipped by default. A shop with no shipping zones still ships anywhere at no charge. | See the notes on the shop in [commerce.md](commerce.md#upgrading-an-existing-installation). |
| **The cart in the header shows only a subtotal.** | The estimate of 7% tax and a flat shipping charge it used to show was never real. | Nothing. |
| **Search is saved on the queue.** | With Meilisearch, `SCOUT_QUEUE` is on unless you set it. | Keep a worker running, or set `SCOUT_QUEUE=false` ([search-index.md](search-index.md#when-meilisearch-is-down)). |
| **The cookie banner has new words.** | It names the theme and menu cookies, which are set whatever was chosen on the banner. | Read your own cookie policy: if it was written before this, it may not say so. |
| **The `social_accounts` table loses four columns.** | A sign-in with Google or Discord no longer keeps the provider's access token, refresh token or raw profile (`access_token`, `refresh_token`, `token_expires_at`, `data`), since nothing used them. `migrate` empties and drops them. | Nothing, except a backup made before it still holds the tokens: keep it private or delete it ([What is kept from a provider](social-login.md#what-is-kept-from-a-provider)). |
| **Members are asked for their password to turn two-factor off or to make new recovery codes, and some cannot unlink a provider.** | Both Security-page buttons take a protection away, so they ask for the password first (good for `AUTH_PASSWORD_TIMEOUT`, three hours unless changed). Unlinking Google, Discord or Steam is refused when the member would have no other way to sign in: no other provider that is on, and no real, verified email address. Staff can still do it with **Disconnect anyway**. | Nothing to run. Members who signed up with a provider and never chose a password use **Forgot your password?** first; those with a made-up `…@oauth.local` address cannot turn two-factor off from the site ([Turning it off](two-factor.md#turning-it-off), [Unlinking and the last way in](social-login.md#unlinking-and-the-last-way-in)). |
| **Changing a password signs the member's other browsers out.** | The site did not do this before: a browser left signed in stayed in for ever after the password was changed or reset. It does now, on any session driver, and Settings → Security has a **Sign out other browsers** button (it asks for the password). On a site that does not keep sessions in the database, the list of open sessions is gone from that page, because it was never true there. | Nothing to run, and **nobody is signed out by the upgrade**: a session stores its fingerprint of the password at its next request. A member's "remember me" cookie from before a password change stops working. API keys are not revoked by a password change ([Browser sessions](two-factor.md#browser-sessions)). |
| **New colours, type and logo.** | The interface, the logo and every icon in `public/` follow the MetaForger design. | If you changed any of them, read [theming.md](theming.md) before you check out the new code. |

## If you changed the code

A site that is a fork, or that has files of its own, finds out about an upgrade through `git` before it finds out through this page. [Keeping it upgradeable](extending.md#keeping-it-upgradeable) says how to keep your changes where an upgrade does not touch them. In particular:

- **Subclasses and overrides in the shop.** `OrderLifecycle`, `OrderRefunder`, `OrderPlacer` and `OrderPricer` each gained a `GiftCards` constructor argument ([commerce.md](commerce.md#upgrading-an-existing-installation)).
- **The Discord companion** is a separate repository with its own releases. It is listed as `dev-main` in `composer.json`.
- **Language files.** `php artisan lang:check` fails when a language lacks a key that English has ([adding-a-language.md](adding-a-language.md)).
- **Pages written for the earlier front end.** Components now come from Reka UI: a Checkbox or Switch binds with `v-model` or `:model-value` and `@update:model-value` (not `v-model:checked`, `:checked` or `@update:checked`, which Vue accepts and which then do nothing; `npm run lint:check` reports them), icons come from `@lucide/vue`, and Tailwind is configured in `resources/css/app.css` (there is no `tailwind.config.js`).
- **A sign-in provider of your own.** `OAuthUser` has no token properties now (`accessToken`, `refreshToken`, `expiresIn` are gone), and `AbstractOAuth2Provider::mapUserToObject()` takes the profile only, not the token response and the access token. A provider written against the old signature fails when it is loaded; take the arguments out ([social-login.md](social-login.md#what-is-kept-from-a-provider)).
- **Code or tests that turn two-factor off or make recovery codes.** `DELETE /settings/security/mfa` (when two-factor is confirmed) and `POST /settings/security/recovery-codes` are behind `password.confirm`, so they answer with a redirect to `/confirm-password` (a `423` to a request that expects JSON) until the session has a recent `auth.password_confirmed_at`. A test sets it with `withSession(['auth.password_confirmed_at' => time()])`. `POST /settings/security/mfa` refuses to run again while two-factor is on.
- **Stripe.** Cashier 16 sets the Stripe API version for the calls the site makes (`2026-08-26.dahlia` with the packages that `composer.lock` names today). If you use metered billing or coupons, read [Cashier's upgrade guide](https://github.com/laravel/cashier-stripe/blob/16.x/UPGRADE.md).
- **Your own tests** should run on the new code before the deploy does: `php artisan test`.

## Checking that it worked

[Checking a deploy](deployment.md#checking-a-deploy) is the list for a deploy. After an upgrade also look at:

| Check | Looks right when |
|---|---|
| `php artisan migrate:status --pending` | Nothing is listed. |
| `storage/logs/laravel.log` | No `critical` line about support attachments. |
| `php artisan support:attachments:privatize --dry-run` | Zero, or only the *missing* ones you know about. |
| `php artisan queue:failed` | Nothing, or only what you know. |
| Sign in as a member, then as an administrator | Both work (everyone was signed out once). |
| **Admin → Access control** | `editor` and `moderator` have what you wrote down, and the new permissions are listed. |
| **Admin → Tokens** | No key still holds only abilities that the site does not have. |
| A picture on a product and on a blog cover | It shows. |
| A test purchase, if you take payments | It is paid, and its email arrives ([payments.md](payments.md)). |

## Going back

The way back is the backup, not `php artisan migrate:rollback`. A migration's `down()` can undo a table, not what the upgrade did to the data around it, and one is empty on purpose: files are never moved back to a public disk.

1. `php artisan down`.
2. Restore the database **and `storage/app`** from the same moment. The files the upgrade moved to the private disk are not where the restored rows say they are if only the database comes back.
3. Check out the earlier commit, `composer install`, `npm ci`, `npm run build`, `php artisan optimize`, `php artisan queue:restart`.
4. `php artisan up`.

People should sign in again after a way back too, because the two builds do not write their sessions the same way.

## What was tried

On 8 November 2026 a database was made with the 75 migrations of the commit of 30 November 2025 ("Hide unauthorized ACP actions", the oldest build tried) on an empty SQLite file. Rows were put into the tables the upgrade changes: two members (member 1 an ordinary member), a blog post and a forum post whose text holds a `<script>` tag and an `onerror` attribute, two API keys (one with the abilities `["read"]`, one with `["*"]`), a support ticket with an attachment row on the public disk, and a Stripe webhook row. Then the code of `main` of that day was run against it:

- **77 migrations ran and none failed**: 69 of the application and 8 of the Discord companion. The tables, columns, indexes and foreign keys that resulted are identical to those of a database made from nothing by the same code (compared as sorted schema dumps).
- The webhook row was given its `provider` and `external_id`. Both keys were kept as they were.
- The attachment was moved to the private disk when its file was on the disk. In a second run with the file missing, the row stayed on the public disk untouched and the command reported it as *missing*.
- `content:sanitize` removed the `<script>` tag, its text and the `onerror` attribute. The blog policy kept the picture and the forum policy dropped it. The paragraph stayed in both.
- The role seeder gave the `admin` role to member 1, an ordinary member. Run a second time it changed nothing (87 permissions, 4 roles).

What has **not** been tried, and so is not promised:

- **An upgrade of a MySQL or PostgreSQL database that holds data.** Their migrations run from empty in CI, and the whole test suite runs on each, but the rehearsal above is SQLite.
- **A build older than 30 November 2025.**
- **A site with changes of its own.**
- **Sessions across the upgrade**: the sign-out is what the code says, not something watched.
- **A real Stripe or Tebex account.** [payments.md](payments.md) says what has been tried with each.
- **Meilisearch across an upgrade.**
