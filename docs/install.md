# Installing MetaForger

One command sets up a site that was just cloned: it checks the machine, makes `.env` and the application key, creates the tables, links `public/storage`, adds the roles, support categories and badges, and makes the first administrator.

```bash
git clone https://github.com/MetaGrenade/laravel-vue.git
cd laravel-vue
composer install
npm install
php artisan metaforger:install
npm run build
composer dev
```

It asks for the name and address of the site, then for the administrator's nickname, email address and password, and shows what it did. Open the address it prints and sign in.

You need PHP 8.4 with the extensions in the README's [Prerequisites](../README.md#prerequisites), Composer 2, Node.js and a database. The command checks the PHP side itself and says what is missing before it changes anything. It does not install PHP, Composer, Node or a database server, and it does not run `npm`: the front end is built by `npm run build`, here and on every deploy.

## What it does, in order

| | Step | What you see |
| --- | --- | --- |
| 1 | **Looks at the machine.** Writes nothing. | A list of what it found. A line marked `FAIL` stops the command; the others are advice. |
| 2 | **Asks what it needs** and checks the answers: the address must start with `http://` or `https://`, the email must be an email, the password must pass the site's password rules. | Questions, or the options you gave. |
| 3 | **Makes `.env`** from `.env.example` when there is none, and sets `APP_NAME` and `APP_URL`. | `Making .env from .env.example` |
| 4 | **Makes the application key** when the site has none. A key that is there is never replaced. | `Making the application key` |
| 5 | **Creates the tables.** Makes the SQLite file when that is the database. Runs the migrations that have not run, nothing else. It reads the database the way Laravel connects to it, so a `DB_URL` counts: it can name another driver, a host or another SQLite file. | The database it will use (without its password), and how many migrations ran. |
| 6 | **Links `public/storage`** to `storage/app/public`, where public uploads (product pictures, blog covers) are served from. | `linked`, or `already linked` |
| 7 | **Adds the roles and their permissions, the support categories and the reputation badges.** | `Adding roles, support categories and badges` |
| 8 | **Makes the administrator.** The account is verified and given the `admin` role by name, whatever its id turns out to be. | `Making the administrator` |
| 9 | **Adds demo data** when you asked for it with `--demo`. | `Adding demo data` |
| 10 | **Adds a starting text for the legal pages** when you asked for it with `--legal-pages` (see [Legal pages](#legal-pages)). | The pages it filled, and the `[Bracketed items]` you still have to fill in |
| 11 | **Tells you what is left**: where to sign in, building the front end, the queue worker and the scheduler, the legal pages, mail and Stripe. | A list |

Steps 1 and 2 happen before anything is written, so a missing PHP extension, a mistyped address or a missing password leaves the checkout as it was.

### What it never does

- It never empties a table and never runs `migrate:fresh`.
- It changes `.env` only in `APP_NAME`, `APP_URL` and `APP_KEY`, and only the lines it was asked to change. Comments, order and everything else stay as they are.
- It never replaces an application key. The key encrypts licence keys and other stored secrets, so a new one would make them unreadable.
- It never gives the administrator role to somebody who already has an account (see [Running it again](#running-it-again)).
- It never adds demo data to a production site, even with `--force`.
- It never lets the demo data reset an administrator. The demo seeders give the account that has one of their email addresses the password `password`, so `--demo` is refused, before anything is written, when the administrator you give has one of those addresses or nicknames, or when the site's administrator already has one of those addresses.

## Options

Ask `php artisan metaforger:install --help` for the same list.

| Option | |
| --- | --- |
| `--app-name=` | The name of the site (`APP_NAME`). Asked for when there is no `.env`; given, it is set in an existing `.env` too. |
| `--app-url=` | The address the site is served from (`APP_URL`): `http://localhost:8000` for `php artisan serve`, or the `.test` address under Herd or Valet. Asked for when there is no `.env`. |
| `--admin-nickname=`, `--admin-email=`, `--admin-password=` | The first administrator. Without a person to ask (`--no-interaction`, a script, CI) all three are needed. |
| `--skip-admin` | Do not make an administrator. |
| `--demo` | Add the demo data (see [Demo data](#demo-data)). |
| `--legal-pages` | Add a starting text for the terms, the privacy policy and the cookie policy where those pages are empty (see [Legal pages](#legal-pages)). |
| `--no-storage-link` | Do not link `public/storage`. |
| `--force` | Run in production. |
| `--no-interaction` | Ask nothing. A question that has no answer from an option stops the command before it writes anything. |

`--admin-password` puts the password on the command line, where other users of the machine can see it while the command runs and where the shell may keep it in its history. Prefer the prompt. In a script, use a password that you change after the first sign-in.

The password has to pass the same rules as a member's. On a production site, and on a fresh clone (which has no `APP_ENV` yet, so Laravel takes it for production) that is at least 12 characters with upper and lower case letters and numbers, and not a password found in a known data leak. Elsewhere it is at least 8 characters.

## What it checks on the machine

| Check | Needed | Why |
| --- | --- | --- |
| PHP 8.4 or newer | yes | `composer.json` requires it |
| The PHP extensions in the README (`bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `session`, `tokenizer`, `xml`, `xmlwriter`, `zip`) | yes | The framework, money arithmetic, the list of languages in Settings → Profile and account data exports use them |
| The PDO extension of the database in `.env` (`pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`) | yes | Without it the site cannot reach its database |
| GD with WebP support | yes | Product pictures and blog covers are saved as WebP |
| `redis` (phpredis), when the cache, the queue or the sessions are set to Redis | yes | See [redis.md](redis.md) |
| `storage`, `bootstrap/cache` (and `database` for SQLite) can be written to | yes | Logs, caches, uploads, the SQLite file |
| `exif` | advice | Photos taken sideways are turned upright |
| `upload_max_filesize` and `post_max_size` at least the largest download staff may upload (`COMMERCE_DOWNLOAD_MAX_KB`) | advice | Larger files are refused by PHP before the site sees them |
| `memory_limit` of 256 MB or more (or none) | advice | With less, a large photo is refused with a message instead of being saved: a 12 megapixel one does not fit in 128 MB. Only for the GD that comes with PHP; a distribution's GD takes its memory from the system, and the installer says so ([images.md](images.md#how-much-memory-a-picture-needs)) |

The command looks at the PHP that runs it, which is the command line one. The web server's PHP (PHP-FPM, Apache, Herd's) can have a different `php.ini`: check the upload limits there too, with `phpinfo()` or `php-fpm -i`.

## Running it again

Every step can be run again. What a second run does depends on whether the site has an administrator:

- **The site has an administrator** (somebody holds the `admin` role). The site counts as set up. Only migrations that have not run are run. The roles, categories and badges are **not** seeded again, because the seeders would put back what staff removed on purpose (a support category, a badge, a permission taken from a role). A second administrator is not made, whatever options you give. `.env` is kept as it is.
- **The site has no administrator and no members.** Everything runs again from where it stopped. This is how a run that failed half way is finished.
- **The database has members but no administrator.** The command stops. The role seeder gives the `admin` role to member 1, and that is not a choice the installer should make for a site that has members. Give the role yourself, after seeding the roles:

  ```bash
  php artisan db:seed --class=BaselineSeeder
  php artisan tinker
  >>> App\Models\User::find(1)->assignRole('admin');
  ```

When a deploy brings new migrations, run `php artisan migrate --force`. The installer would do the same, but it is meant for the first setup. Moving a site that has been running to a newer version is [upgrading.md](upgrading.md).

Keep a copy of `.env` somewhere safe. A site that has lost its `.env` is not recognised by the command, which makes a new one with a new application key, and a new key cannot read what the old one encrypted (licence keys, two-factor secrets). Put the old `.env` back instead.

## Production

Set the site up by hand first, then run the installer on it:

1. Copy `.env.example` to `.env` and set at least `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, the `DB_*` settings (create the empty MySQL or PostgreSQL database yourself: the command makes only the SQLite file), mail, and Redis if you use it. [configuration.md](configuration.md#before-a-site-goes-live) lists the rest.
2. Run `php artisan metaforger:install --force`. Without `--force` a site that says it is production asks you to confirm and, with `--no-interaction`, stops.
3. Build the front end, run `php artisan optimize`, and start the queue worker and the scheduler. [deployment.md](deployment.md) has all of this in order, with a web server, Supervisor and cron to copy, and what to do on every deploy after the first; [configuration.md](configuration.md) lists every setting.

`--demo` is refused in production. The demo accounts have a password everybody knows.

A machine that has no `.env` and no `APP_ENV` in its environment is not taken for production, although Laravel's own default is `production`: the `.env` the command makes from the example (`APP_ENV=local`) is what the site will run as. Set `APP_ENV=production` in the environment, or give `--env=production`, to have it treated as production.

## Doing it by hand

The installer does these steps for you and checks the machine first; do them yourself when you want to. They give the same site.

1. **Environment.**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Set `APP_URL` to the address you will browse, and the database credentials and any other service keys ([configuration.md](configuration.md)).
2. **Tables.**

   ```bash
   php artisan migrate
   ```

   With the default SQLite connection Laravel offers to create `database/database.sqlite`. The migrations create every table the site needs, including the session, cache and queue tables the default `database` drivers use.
3. **Storage link.**

   ```bash
   php artisan storage:link
   ```

   Public uploads (product pictures, blog covers) are served from `storage/app/public`. Private files (support attachments and digital downloads) stay on the `local` disk and never need the link.
4. **Roles, support categories and badges.**

   ```bash
   php artisan db:seed --class=BaselineSeeder
   ```

   It is for a site that has none of this yet: run again, its seeders put back what staff removed on purpose (a category, a badge, a permission taken from a role), so do not run it on a site that is set up.
5. **An administrator.** Register an account, then give it the `admin` role:

   ```bash
   php artisan tinker --execute="App\Models\User::find(1)->assignRole('admin');"
   ```

   (Use the account's id if it is not 1. `RolePermissionSeeder` also gives the role to the user with ID 1 when it runs, which is why the installer makes the account by name and does not rely on that.)

Then build the front end (`npm run build`) and start the site as in [Developing](development.md#starting-the-site), or on a server as in [deployment.md](deployment.md). On a production site **never run a plain `php artisan db:seed`**: it makes a demo account with a password everybody knows. For the legal pages, run `php artisan db:seed --class=LegalPagesSeeder` (see [Legal pages](#legal-pages)).

## Demo data

`php artisan metaforger:install --demo` adds, after the administrator is made:

- the subscription plans, with the placeholder Stripe prices `price_starter` and `price_pro` (they work only after you create the prices in Stripe and set them in `.env`);
- two demo accounts for the token log (`token-log-admin@example.com` and `token-log-service@example.com`), with the password `password`;
- a demo shop: products with variants, shipping zones, tax rates and the discount codes `WELCOME10`, `SAVE5` and `FREESHIP`;
- a poll.

It is for a machine where you try the site out. `php artisan db:seed` does the same without the installer, adds the starting text for the legal pages, and also makes `test@example.com` (password `password`) the administrator; see [Doing it by hand](#doing-it-by-hand). Two more demo seeders are separate and opt-in: `ForumDemoSeeder` (it **empties the forum tables** first) and `AcpDashboardDemoSeeder` (a year of activity for the dashboard charts).

Do not give the administrator one of the two token-log addresses or the nicknames `Token Log Admin` and `Token Log Service` when you add `--demo`: the command stops and says so, because the demo seeder would otherwise reset that account's password to `password`.

## Legal pages

`php artisan metaforger:install --legal-pages` fills the terms, the privacy policy and the cookie policy with the starting text from [legal-pages.md](legal-pages.md), for the pages that are empty. A page that already has text is never touched, so it is safe on a site that is set up (`php artisan db:seed --class=LegalPagesSeeder` does the same without the installer).

It is asked for, not done for everyone, because **a page is public as soon as it has text**: the footer links to it and the sitemap lists it. The text has ten `[Bracketed items]` that only you can fill in (your name and address, a contact email, the refund period, the governing law). The command lists the ones left, and each page opens with a note that it is a starting point and not legal advice. Fill them in, delete the note, and have the pages read by someone who can give legal advice before the site opens to the public.

## When something goes wrong

| You see | What to do |
| --- | --- |
| `FAIL: missing: …` on PHP extensions | Install them: `php -m` lists what is loaded and `php --ini` says which `php.ini` is read. Debian and Ubuntu: `sudo apt install php8.4-<name>`. Herd has all of them. |
| `FAIL` on GD with WebP | `php -r 'var_dump(gd_info()["WebP Support"]);'` must print `bool(true)`. Install `php8.4-gd` (Debian, Ubuntu), or enable `extension=gd` in `php.ini` (Windows). Packages built without libwebp need replacing. |
| `FAIL` on a writable folder | Give the user that runs PHP write access: `chmod -R ug+rwX storage bootstrap/cache`, and `chown` them to the web server's user. |
| The database cannot be reached | Set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `.env`, create the empty database, and run the command again. |
| `Could not link public/storage` | Run `php artisan storage:link` yourself. On Windows, making a symbolic link needs Developer Mode or an elevated terminal. |
| `The configuration is cached` | Run `php artisan config:clear`; a cached configuration ignores `.env`. |
| `has members but no administrator` | See [Running it again](#running-it-again). |
| `The demo data has an account with the address …` or `… with the nickname …` | Give the administrator another address or nickname, or leave out `--demo`. See [Demo data](#demo-data). |
| The page loads without styles or scripts | The front end is not built: `npm run build`. |

## How it is tested

- `tests/Unit/Install` covers the `.env` writer (quoting, Windows line endings, comments kept, a value with `$` in it) and the machine checks, which are fed machines that lack things. Two tests keep the extension list and the PHP version in the code equal to the README and `composer.json`.
- `tests/Feature/Install` runs the command against a copy of the migrated schema, with an `.env` in a folder of its own: a fresh site, an existing `.env` kept byte for byte, the key, the prompts, every refusal, a site that is already installed, production, `--demo` (and the administrators it must not reset), `--legal-pages`, and a `DB_URL` that names another driver or another SQLite file than the plain settings do. `DatabaseSeederTest` seeds the whole demo site, and is written to pass on MySQL and PostgreSQL, where the id of the first member is not 1 after other tests have run.
- The `install` job in CI runs it for real on a checkout as a clone leaves it (no `.env`, no database), runs it a second time to show that nothing changes, serves the site, and signs in as the administrator it made with `scripts/check-install.mjs`. Run that script yourself against any site: `node scripts/check-install.mjs http://127.0.0.1:8000 you@example.test 'password'`.
