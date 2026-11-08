# Deploying MetaForger

How to put a MetaForger site on a server you run: a VPS, or one that Laravel Forge manages for you. It covers what a live site is made of, the first deploy, every deploy after it, what changes behind a proxy or a load balancer, and what to check afterwards. The settings it names are in [configuration.md](configuration.md).

> **Status:** the application's own steps (the installer, the build, the queue command, the scheduler, the proxy settings) are what the tests and CI run. The nginx, Supervisor and Forge parts follow their documentation and were **not run** for this guide: look at what they do on your server before you rely on them. The Docker image and its compose file have their own guide, [docker.md](docker.md), written after the image was built and run.

## What a live site is made of

A site is more than a web server. Two of its parts are separate programs that nothing starts for you, and they are the two that are most often forgotten.

| Part | What it does | If it is missing |
| --- | --- | --- |
| Web server and PHP-FPM | Answers requests. PHP 8.4 with the extensions in [install.md](install.md#what-it-checks-on-the-machine); the document root is `public/`. | There is no site. |
| The database | MySQL 8 or PostgreSQL 16. SQLite is for a laptop. | |
| **A queue worker** | Sends the emails (they are queued on `mail`; only the two that Laravel sends itself, to verify an address and to reset a password, go out at once) and runs the jobs that the shop and the scheduler start. | Emails wait in the queue for good, and nothing says so. |
| **The scheduler** | One cron line that runs every minute. It puts the timed jobs on the queue: releasing the stock held by unpaid orders (every five minutes), lifting suspensions that have ended (every minute), checking support SLAs (every fifteen), the email digests (08:00 UTC), refreshing the Tebex catalogue and pruning old logs (at night). | Unpaid orders hold stock for ever, suspensions never end, no digests are sent. **The worker is what runs them:** a scheduler without a worker only fills the queue. |
| Redis | The cache, the sessions and the queue, off the database ([redis.md](redis.md)). Recommended, not required. | The database does it all. |
| Node.js | Builds the front end with `npm run build`. Needed on the machine that builds (22.13 or newer; `.nvmrc` names 24), and on the server only for server-side rendering. | |
| Meilisearch, an SSR server | Only if you switch them on ([search-index.md](search-index.md); [Server-side rendering](#server-side-rendering-optional) below, which is a third process to keep running and to restart on every deploy). | |

You also need a domain that points at the server and a certificate for it (Let's Encrypt is free), mail credentials for a real mailer, and somewhere safe to keep a copy of `.env`.

## The first deploy

The commands assume the site lives in `/var/www/metaforger`, that a user called `deploy` owns it, and that PHP-FPM runs the site as that user (the arrangement Forge makes, with a pool for each site). If PHP-FPM runs as `www-data`, give it write access to `storage` and `bootstrap/cache`, and run `php artisan` as that user (`sudo -u www-data php artisan ...`): a log file that the console creates as another user cannot be written to by the site.

**1. Get the code**, at a release and not a moving branch:

```bash
git clone https://github.com/MetaGrenade/laravel-vue.git /var/www/metaforger
cd /var/www/metaforger
git checkout <the tag of the release you are deploying>
```

**2. Install the PHP packages** without the test tools:

```bash
composer install --no-dev --optimize-autoloader
```

**3. Make the database.** The installer makes only the SQLite file, so create an empty MySQL or PostgreSQL database yourself, with a user of its own that has rights to that database only:

```sql
CREATE DATABASE metaforger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'metaforger'@'localhost' IDENTIFIED BY 'a-long-password';
GRANT ALL PRIVILEGES ON metaforger.* TO 'metaforger'@'localhost';
```

**4. Write `.env`.** Copy the example and set what the checklist in [Before a site goes live](configuration.md#before-a-site-goes-live) lists, at least:

```bash
cp .env.example .env
```

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
DB_CONNECTION=mysql
DB_DATABASE=metaforger
DB_USERNAME=metaforger
DB_PASSWORD=a-long-password
SESSION_SECURE_COOKIE=true
MAIL_MAILER=smtp
# ... MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS
```

Leave `APP_KEY` empty: the installer makes it. `chmod 600 .env`.

**5. Build the front end.** `public/hot` must not exist (it comes from a development machine and makes pages look for the Vite dev server):

```bash
npm ci
npm run build          # npm run build:ssr, if you use server-side rendering (see below)
```

**6. Run the installer** (`--force` because this is production; it asks for the first administrator, whose password has to pass the production rules, see [install.md](install.md#production)):

```bash
php artisan metaforger:install --force
```

It checks the machine before it writes anything, makes the application key, creates the tables, links `public/storage`, adds the roles, support categories and badges, and makes the administrator. Add `--legal-pages` to have it fill the terms, the privacy policy and the cookie policy with a starting text where they are empty (read it and fill in the bracketed items first: [legal-pages.md](legal-pages.md)). **Back up `.env` now.** A new application key cannot read what the old one encrypted.

**7. Cache the configuration** (and the routes, events and views):

```bash
php artisan optimize
```

**8. Point the web server at `public/`.** This is the standard Laravel server block for nginx, with the changes this application needs. TLS is added by Certbot (`sudo certbot --nginx -d example.com`) or by Forge, which also redirects HTTP to HTTPS.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name example.com;
    root /var/www/metaforger/public;

    index index.php;
    charset utf-8;

    # The largest upload: COMMERCE_DOWNLOAD_MAX_KB (100 MB by default). nginx's own default is 1 MB.
    client_max_body_size 100m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # No `location = /robots.txt` here, as Laravel's sample has: the application writes /robots.txt,
    # /sitemap.xml, /site.webmanifest and /.well-known/security.txt, and there is no file for nginx to find.

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Dotfiles, except /.well-known.
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Compression, caching of `/build/assets/` for a year, OPcache and HTTP/2 are in [the production checklist](performance.md#a-production-checklist). PHP's own limits have to allow the same uploads: `upload_max_filesize` and `post_max_size` at least as large as `COMMERCE_DOWNLOAD_MAX_KB`, in the `php.ini` that PHP-FPM reads (not the command line's).

**9. Keep a queue worker running.** Under Supervisor (`/etc/supervisor/conf.d/metaforger-worker.conf`), then `sudo supervisorctl reread && sudo supervisorctl update`:

```ini
[program:metaforger-worker]
command=php /var/www/metaforger/artisan metaforger:queue work database --sleep=3 --tries=3 --max-time=3600
user=deploy
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
```

Use `redis` in place of `database` when `QUEUE_CONNECTION=redis`. **It has to be `metaforger:queue`, not `queue:work`:** a plain worker never reads the `mail` queue, so no email would go out. [redis.md](redis.md#the-queue) explains why, and what to write in a panel that only has a queue field (`default,mail`).

**10. Run the scheduler.** One line in the crontab of the same user (`crontab -e -u deploy`):

```cron
* * * * * cd /var/www/metaforger && php artisan schedule:run >> /dev/null 2>&1
```

Run it on **one** machine only: the scheduled jobs are not locked against each other, so two servers would do each of them twice. `php artisan schedule:list` shows what it will run and when.

**11. Check it** (see [Checking a deploy](#checking-a-deploy)).

Then the parts that need an account somewhere else: the shop's payments ([payments.md](payments.md#going-live): the live Stripe keys and the webhook endpoint `https://example.com/stripe/webhook`, or Tebex, [tebex.md](tebex.md)), sign-in with Google, Discord or Steam ([social-login.md](social-login.md)), and the legal pages ([legal-pages.md](legal-pages.md): they are public as soon as they have text).

## Every deploy

```bash
cd /var/www/metaforger
git fetch --tags && git checkout <the tag>
composer install --no-dev --optimize-autoloader
npm ci
npm run build                         # npm run build:ssr, with server-side rendering
php artisan migrate --force
php artisan api:docs
php artisan optimize
php artisan queue:restart
php artisan inertia:stop-ssr --graceful   # with server-side rendering only
sudo systemctl reload php8.4-fpm      # when OPcache is set not to check for changes
```

- **Never `php artisan db:seed` on a live site.** The default seeder adds a demo account with a known password. `migrate --force` is all a deploy needs; the installer is for the first setup.
- **`php artisan api:docs`** because the API description at `/api/docs/openapi.json` is written to `storage/app/api-docs/openapi.json` once and served from that file until it is written again. Without it a site keeps describing the API of the release that first wrote it, and the address it was written under ([api.md](api.md#the-openapi-document)).
- **`php artisan queue:restart`** because workers hold the old code in memory until they are told to stop. Supervisor then starts new ones. (After a change to `.env` too, which a worker also keeps.)
- **Server-side rendering needs two more things on every deploy:** `npm run build:ssr` in place of `npm run build` (`build` makes only the browser files), and `php artisan inertia:stop-ssr --graceful` so that the rendering server starts again with the new bundle. [Server-side rendering](#server-side-rendering-optional) says why.
- **Reload PHP-FPM** when OPcache has `validate_timestamps=0`, as [the checklist](performance.md#a-production-checklist) recommends, or the old code goes on running.
- **Read the CHANGELOG** for what the release changes. A release that needs more than this says so under *Upgrading*. [Upgrading](upgrading.md) is the longer guide for a site that has been running a while: the backup, a rehearsal on a copy, the steps that come after the migrations and are not done for you (the role seeder among them), what behaves differently, and how to go back.
- Put `php artisan down --refresh=15` before and `php artisan up` after for a deploy that changes the database in a way that old code cannot live with. A site on several servers sets `APP_MAINTENANCE_DRIVER=cache` first, so that they all see the flag.
- **Building on the server is not required.** `npm run build` can run in your CI or on your machine, and the `public/build` folder be copied to the server, which then needs no Node. Do not copy `public/hot`. With server-side rendering, copy `bootstrap/ssr` too: that server runs on Node, so the machine it runs on needs Node after all.

## Server-side rendering (optional)

Pages are rendered in the browser. With server-side rendering the site first asks a small Node program for each page's HTML, so that crawlers are given written pages (the settings are under [Server-side rendering](configuration.md#server-side-rendering)). It is a **third process**, next to the queue worker and the scheduler. When it is not running, or does not answer, pages fall back to the browser: nothing breaks, they are just not rendered in advance.

1. Set `INERTIA_SSR_ENABLED=true` in `.env`, then `php artisan optimize`.
2. Build with **`npm run build:ssr`** in place of `npm run build`. `build` makes only the browser files; `build:ssr` also makes the server's bundle, `bootstrap/ssr/ssr.js`.
3. Keep `php artisan inertia:start-ssr` running under Supervisor, as the worker is. It stays in the foreground, listens on `INERTIA_SSR_URL` (`http://127.0.0.1:13714`) and exits when it is told to stop, so Supervisor has to start it again whatever the exit code: `autorestart=true`, not `unexpected`.

   ```ini
   [program:metaforger-ssr]
   command=php /var/www/metaforger/artisan inertia:start-ssr
   user=deploy
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   ```

4. **Restart it on every deploy:** `php artisan inertia:stop-ssr --graceful`. The rendering server keeps the bundle it started with in memory, as a queue worker keeps the code. A deploy that only rebuilt the files would leave it rendering the previous release's components while browsers load the new ones, and the page can flash or fail to hydrate. Supervisor starts it again with the new bundle; `--graceful` makes the command succeed when it is not running yet (the first deploy).

`php artisan inertia:check-ssr` says whether it answers.

## On Forge

Create the site with PHP 8.4 and the web directory `public`, point it at the repository, and let Forge make the certificate. Then:

- **Environment:** paste the `.env` from step 4. After a change, deploy, so that `php artisan optimize` runs again.
- **Deployment script:** the commands under [Every deploy](#every-deploy), in Forge's form (it fills in `$FORGE_PHP`, `$FORGE_COMPOSER` and the PHP-FPM reload for you):

  ```bash
  cd $FORGE_SITE_PATH
  git pull origin $FORGE_SITE_BRANCH
  $FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
  npm ci
  npm run build                       # npm run build:ssr, with server-side rendering
  $FORGE_PHP artisan migrate --force
  $FORGE_PHP artisan optimize
  $FORGE_PHP artisan queue:restart
  $FORGE_PHP artisan inertia:stop-ssr --graceful   # with server-side rendering only
  ```

  The first deploy needs `$FORGE_PHP artisan metaforger:install --force` run once on the server, as in step 6.
- **Scheduler:** switch Forge's scheduler on for the site. It is the cron line of step 10.
- **Server-side rendering:** a second daemon, `php artisan inertia:start-ssr`, with the site's directory, and the two changes to the deployment script above. See [Server-side rendering](#server-side-rendering-optional).
- **Queue worker:** a daemon whose command is `php artisan metaforger:queue work redis --sleep=3 --tries=3 --max-time=3600` (or `database`), with the site's directory. If you use Forge's own queue-worker form instead, give it the queues `default,mail`, as [redis.md](redis.md#the-queue) says.
- **Proxy settings:** a site whose HTTPS ends in the nginx on the same server needs nothing. If a load balancer sits in front, set `TRUSTED_PROXIES` as under [Behind a proxy or a load balancer](#behind-a-proxy-or-a-load-balancer).

## Behind a proxy or a load balancer

HTTPS can end in two places.

- **At the web server that runs PHP** (nginx or Apache with the certificate, PHP-FPM behind it, as in the steps above). PHP is told that the request was secure, and nothing in this section applies.
- **In front of it.** A load balancer, Cloudflare, Traefik or Caddy in front of a container, an ingress: they take the HTTPS request and hand the application plain HTTP with an `X-Forwarded-Proto: https` header. Laravel believes those headers only from the proxies it has been told about, and until it is told, the site sees every visitor as plain `http://` and as coming from the proxy's address. That breaks four things, none of them loudly:
  - `Strict-Transport-Security` is never sent, because it is sent only on a request that looks secure.
  - A **signed link** made as `https://` is refused with a 403 when it is opened, because its signature is checked against the `http://` address the request seems to have. That is the email verification link, the link in an order email, a purchased download, a data export and the Tebex return address.
  - Every visitor comes from one address, so the limits kept for each address (at most 20 sign-in attempts a minute from one address, and the limits on two-factor codes, password confirmation, API sign-in and downloads) are shared by everybody on the site, and a few people typing wrong passwords lock out the rest.
  - The audit log and the API token log record the proxy as where everything came from.

  Tell the site which proxies to believe:

  ```dotenv
  APP_URL=https://example.com
  APP_FORCE_HTTPS=true
  TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
  SESSION_SECURE_COOKIE=true
  ```

  `TRUSTED_PROXIES` takes the addresses or ranges of the proxies, separated by commas. `*` (or `REMOTE_ADDR`) believes whoever connects, which is right only when PHP cannot be reached except through the proxy, by a firewall or a private network: otherwise anybody can send an `X-Forwarded-For` of their choice and choose which address the limits count. Cloudflare publishes its [addresses](https://www.cloudflare.com/ips/): list them, or close the server to every other address and use `*`. Run `php artisan optimize` after the change.

  Check it: `curl -sI https://example.com/ | grep -i strict-transport-security` prints a line, and the link in a verification email opens. `TrustedProxiesTest` holds the rule in place.

## Files, mail and backups

- **Uploads.** `php artisan storage:link` was run by the installer. Public pictures are served from `storage/app/public`; support attachments, forum attachments and digital downloads are on private disks and only handed out through routes that check who is asking ([private-uploads.md](private-uploads.md)). Put the pictures on S3 or a CDN with `PUBLIC_DISK_URL` ([images.md](images.md#using-a-cdn)). The limits are `client_max_body_size` (nginx), `upload_max_filesize` and `post_max_size` (PHP), and `COMMERCE_DOWNLOAD_MAX_KB` (the site): the smallest one wins.
- **Mail.** The default mailer writes emails to the log. Set `MAIL_MAILER` and what it needs, deploy, and send one:

  ```bash
  php artisan tinker
  >>> Mail::raw('It works.', fn ($m) => $m->to('you@example.com')->subject('MetaForger test'));
  ```

  That is sent at once and shows a wrong password or port. The site's own emails go through the queue, so also check that a worker is running. Your mail service has to be allowed to send for `MAIL_FROM_ADDRESS` (SPF and DKIM), or the emails go to spam.
- **Logs.** `LOG_STACK=daily` with `LOG_LEVEL=warning` keeps `storage/logs` from growing without end; `LOG_DAILY_DAYS` says how long. Failed jobs are in the database: `php artisan queue:failed`, and `php artisan queue:retry all` after the cause is fixed.
- **What to back up:** the database, `.env` (the application key is in it), and `storage/app` (private files and, unless they are on S3, the public pictures). Not `vendor`, `node_modules` or `public/build`: a deploy makes those. Try a restore on another machine before you need one.

## Checking a deploy

| Check | Looks right when |
| --- | --- |
| `curl -I https://example.com/up` | `200`. It says the application starts; it does not look at the database. |
| `curl -sI https://example.com/ \| grep -i strict-transport` | A line is printed. If not, see [Behind a proxy or a load balancer](#behind-a-proxy-or-a-load-balancer). |
| `php artisan about` | Environment `production`, debug mode `OFF`, config, routes, events and views `CACHED`. |
| `php artisan schedule:list` | The jobs of [What a live site is made of](#what-a-live-site-is-made-of) are listed. |
| `php artisan queue:failed` | Nothing, or only what you know. |
| A registration, if verification is required | The email arrives and its link opens. |
| `npm run ttfb -- https://example.com` | Under 200 ms per page ([performance.md](performance.md)). |
| `php artisan metaforger:search:health` | When Meilisearch is on. |

## When something is wrong

| You see | Look at |
| --- | --- |
| A blank page or a 500 right after a deploy | `storage/logs/laravel.log`. Usually a folder `storage` or `bootstrap/cache` that PHP cannot write to, or a package missing because `composer install` did not run. |
| A change to `.env` does nothing | The configuration is cached. `php artisan optimize`, then `php artisan queue:restart`. |
| The page has no styles, or looks for `localhost:5173` | `npm run build` was not run, or `public/hot` exists. Delete it. |
| No email arrives | `MAIL_MAILER` is still `log`, or no worker is running (`sudo supervisorctl status`), or the job failed (`php artisan queue:failed`). |
| Unpaid orders never expire; no digests | The cron line is missing or runs as the wrong user. |
| A link in an email (verification, order, download) says **403 Invalid signature** | The proxy is not trusted: [Behind a proxy or a load balancer](#behind-a-proxy-or-a-load-balancer). Or `APP_URL` is not the address people use. |
| Everyone is locked out of signing in at once | The same: every visitor has the proxy's address. |
| Signing in loops back to the form | `SESSION_SECURE_COOKIE=true` on a site that is served over plain HTTP, or `APP_URL` is not the address in the browser. |
| Everyone was signed out | `APP_KEY` changed, or `SESSION_DRIVER` was switched. |
| An upload fails | The smallest of the limits under [Files, mail and backups](#files-mail-and-backups). |
| Stripe or Tebex says the webhook fails | The endpoint is reachable over HTTPS from outside, and the signing secret is the one of the **live** endpoint ([billing.md](billing.md#webhook-events)). |
| The browser blocks a script or a connection | The Content Security Policy. Try `CSP_REPORT_ONLY=true` and add the host ([security-headers.md](security-headers.md#adding-a-third-party-host)). |

## Not covered

- **Docker.** [docker.md](docker.md) is the guide to the image and the compose file, for one machine. A Docker setup for several machines, or Kubernetes, has not been tried.
- **Several web servers** behind a load balancer: sessions, the cache and the queue on Redis, `APP_MAINTENANCE_DRIVER=cache`, uploads on shared storage, and the scheduler on one of them. Each is described above; the whole has not been tried.
- **Octane** ([octane.md](octane.md): audited, not run) and **Horizon** ([redis.md](redis.md#adding-horizon)).
- **Laravel Cloud and Vapor.**
