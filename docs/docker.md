# Running MetaForger in Docker

How to run a MetaForger site from a Docker image on one machine: the image, the compose file that starts it with MySQL and Redis, the first start, every update after it, HTTPS, backups, and what to do when something does not start. The settings it names are in [configuration.md](configuration.md); the same site on a server without Docker is [deployment.md](deployment.md).

> **Status:** the image was built and run for this guide, with Docker Desktop on Windows (Engine 28.4, Compose 2.39, Linux containers, amd64), and a `docker` job in CI builds it and runs [`scripts/docker-smoke.mjs`](../scripts/docker-smoke.mjs) on every change ([How it is tested](#how-it-is-tested)). What was run: the installer on MySQL 8.0, the site over plain HTTP, over HTTPS with Caddy's own local certificate, and behind forwarded `X-Forwarded-*` headers; an email queued by the web container and sent by the worker through Redis; the scheduler putting a job on the queue; a stop and a start with the data kept; maintenance mode, `migrate` and `api:docs` as one-off containers; stopping a worker in the middle of a job; a PHP file planted in the public storage folder; the backup and restore commands below, run on the same stack; a second worker started with `--scale` and stopped again. What was **not** run: a certificate from Let's Encrypt (it needs a public domain name), HTTP/3, a real mail provider, two workers sharing a load, an arm64 machine, a restore of the backups onto another machine, the installer's questions at a terminal (it was given its answers as options, as CI does), and everything under [Not covered](#not-covered). The image is 924 MB.

## What you get

One image, started three ways, plus the two services it needs.

| Service | What it runs | Notes |
| --- | --- | --- |
| `app` | The web server: FrankenPHP (Caddy with PHP in it) on ports 80 and 443 | The only one that answers visitors. Gets a certificate by itself when `SERVER_NAME` is a domain name. |
| `worker` | `php artisan metaforger:queue work redis ...` | Sends the queued emails and runs the jobs the shop and the scheduler start. A stop waits up to 90 seconds for the job it is running. It finishes and exits once an hour on purpose (`--max-time`, so that memory a job leaked is given back), and so does `queue:restart`; Docker starts it again each time. |
| `scheduler` | `php artisan schedule:work` | Puts the timed jobs on the queue, once a minute. **Exactly one**; the worker runs them. |
| `mysql` | MySQL 8.0 | Reachable from the other containers only. |
| `redis` | Redis 7 | The cache, the sessions and the queue ([redis.md](redis.md)). Reachable from the other containers only, no password, no published port; it keeps its data in an append-only file, and refuses a write when it is full instead of dropping a job or a session. |

All of it is in [`compose.production.yaml`](../compose.production.yaml), which is a different file from `compose.yaml` (the backing services for developing on your own machine; see the README). The two have different project names, so their containers and volumes never meet. **Every command below names the file with `-f`**, because a plain `docker compose` in this folder reads `compose.yaml`.

The image holds the code, the PHP packages (without the test tools) and the built front end. The settings (`.env`) and the site's files (`storage`) are not in it: they come in when it runs, which is why one image serves all three roles. PHP in it has the extensions the installer asks for and `pcntl` besides, which a worker needs to finish its job when it is told to stop. It runs as `www-data`, not as root. OPcache is set not to look for changed files (`validate_timestamps=0`, as [performance.md](performance.md#a-production-checklist) advises), so **a change to the code is a new image**, never an edit inside a container.

## Before you start

- **Docker Engine with Compose v2** (`docker compose version`; the file was run with 2.39 and uses `init`, `depends_on` with `service_healthy` and `up --wait`). Docker Desktop on Windows or macOS works for trying it; for a live site use a Linux machine.
- **A few minutes and a connection for the first build.** It downloads the base images (PHP, Node, Composer, MySQL, Redis), runs `composer install` and `npm ci`, and fetches the fonts from Bunny, which the build then serves from your own site. Later builds reuse what has not changed.
- **For a live site:** a domain name pointing at the machine, ports 80 and 443 open to the world, mail credentials for a real mailer, and somewhere safe to keep a copy of `.env` and the database dumps.

## The first start

**1. Get the code**, at a release and not a moving branch, and make the settings file:

```bash
git clone https://github.com/MetaGrenade/laravel-vue.git metaforger
cd metaforger
git checkout <the tag>
cp .env.example .env
```

**2. Set these in `.env`** (the others are in [configuration.md](configuration.md); every one of them works here, because the containers get the whole file):

| Setting | Set it to |
| --- | --- |
| `APP_URL` | The address visitors use, `https://example.com`, with no trailing slash. Emails, queued jobs and the sitemap build their links from it. |
| `DB_PASSWORD` | A password you make up for the site's database user. **Required**: Compose stops without it. |
| `DB_ROOT_PASSWORD` | A second password, for MySQL's own administrator. **Required.** |
| `MAIL_MAILER` and the `MAIL_*` it needs | A real mailer. The example file writes every email to the log. |
| `SESSION_SECURE_COOKIE` | `true` once the site is served over HTTPS. Leave it unset to try the site over plain HTTP: with it set, signing in loops back to the form ([configuration.md](configuration.md#before-a-site-goes-live)). |
| `SERVER_NAME`, `APP_HTTP_PORT`, `APP_HTTPS_PORT` | How visitors reach the site: see [HTTPS](#https). With none of them set the site answers plain HTTP on port 80. |

`APP_KEY` is next. The settings the compose file fixes itself (`APP_ENV`, `APP_DEBUG`, the database and Redis addresses, the cache, queue and session drivers, where logs go) are given in the file and win over `.env`, so a line for them in `.env` does nothing.

**3. Build the image:**

```bash
docker compose -f compose.production.yaml build
```

**4. Make the application key** and put what it prints into `.env` as `APP_KEY=base64:...`:

```bash
docker compose -f compose.production.yaml run --rm --no-deps app php artisan key:generate --show
```

It is the only command that runs without a key; any other container refuses to start with an empty one and says how to make it. **Back up `.env` now.** A new key cannot read what the old one encrypted ([configuration.md](configuration.md#before-a-site-goes-live)).

**5. Start the database and Redis**, and wait until they answer:

```bash
docker compose -f compose.production.yaml up -d --wait mysql redis
```

**6. Run the installer** in a container of its own. `--force` because this is production; it asks for the first administrator, whose password has to pass the production rules ([install.md](install.md#production)). It runs the 150-odd migrations, which takes a minute or two:

```bash
docker compose -f compose.production.yaml run --rm app php artisan metaforger:install --force
```

The installer finds the empty `.env` that the image carries, keeps it, and uses the settings from the environment. Add `--legal-pages` to have it fill the terms, the privacy policy and the cookie policy with a starting text ([legal-pages.md](legal-pages.md)). Without a terminal, give it the answers as options (`--no-interaction --admin-nickname=... --admin-email=... --admin-password=...`, see [install.md](install.md)).

**7. Start everything:**

```bash
docker compose -f compose.production.yaml up -d
```

Look at `docker compose -f compose.production.yaml ps`: `app` shows `healthy` after a few seconds. Open the address, sign in as the administrator, and check [the parts that need an account somewhere else](deployment.md#the-first-deploy) (Stripe or Tebex, sign-in with Google, Discord or Steam). To check from the command line that the administrator can sign in and the main pages open, run `node scripts/check-install.mjs http://127.0.0.1 you@example.test 'the password'`.

## HTTPS

`SERVER_NAME` in `.env` decides what the web server does. Pick one.

| You want | Set | What happens |
| --- | --- | --- |
| **The container to get the certificate itself**, on a machine that visitors reach directly | `SERVER_NAME=example.com` and `APP_URL=https://example.com`; leave the ports alone | Caddy asks Let's Encrypt for a certificate and renews it, answers on 443 (HTTP/2, and it announces HTTP/3) and redirects port 80 to it. The domain has to point at the machine, and ports 80, 443 and 443/udp have to be open, or no certificate comes. **Keep the `caddy-data` volume**: it holds the certificates, and Let's Encrypt limits how many it gives for a name. |
| **A proxy or load balancer in front** that ends HTTPS | Nothing for `SERVER_NAME` (it stays `:80`). `APP_HTTP_PORT=127.0.0.1:8080` to publish the port only to the machine itself, and `APP_FORCE_HTTPS=true` with `TRUSTED_PROXIES` as in [deployment.md](deployment.md#behind-a-proxy-or-a-load-balancer) | The container answers plain HTTP and believes the proxy's `X-Forwarded-*` headers, so links, `https` detection, HSTS and the visitor's address come out right. Without `TRUSTED_PROXIES` those headers are ignored and the site builds `http://` links. `*` is safe only when nothing but the proxy can reach the port. |
| **To try it on your own machine** | `SERVER_NAME=localhost` for HTTPS with a certificate from Caddy's own authority (the browser warns, `curl -k` does not mind), or nothing for plain HTTP; `APP_HTTP_PORT=8080` and `APP_HTTPS_PORT=8443` when 80 and 443 are taken | The redirect from HTTP goes to port 443, wherever you published it: use the HTTPS address yourself. |

The web server's own health check asks a second address that only the container can reach (`127.0.0.1:8081`), so that it works whatever `SERVER_NAME` is.

## Every update

```bash
git fetch --tags && git checkout <the tag>
docker compose -f compose.production.yaml build
docker compose -f compose.production.yaml run --rm app php artisan migrate --force
docker compose -f compose.production.yaml run --rm app php artisan api:docs
docker compose -f compose.production.yaml up -d
```

- **`build --pull`** also fetches newer base images, which is how PHP, FrankenPHP and Debian security fixes reach you. Do it now and then, and test the result.
- **`migrate --force`** is all a deploy needs; never `db:seed` on a live site ([deployment.md](deployment.md#every-deploy)). **`api:docs`** because the API description is written once into `storage`, which survives an update, and is served from there until it is written again.
- **`up -d`** replaces every container whose image or settings changed. The worker is told to stop, finishes the job it is running (up to 90 seconds), and a new one starts on the new code, so there is no separate `queue:restart`. The web container is replaced, which takes a few seconds in which a visitor gets no answer: for a change to the database that old code cannot live with, put `docker compose -f compose.production.yaml run --rm app php artisan down --refresh=15` before the migration and `... up` after. The maintenance flag is in the `storage` volume, so every container sees it.
- **After a change to `.env`**, `up -d` is enough: it sees that the settings changed and recreates the containers. A change to a setting that is built into the browser files needs `build` as well (below).
- [upgrading.md](upgrading.md) is the longer guide for a site that has been running a while: the backup, a rehearsal on a copy, the steps after the migrations, and how to go back. Its commands are these, run in the containers.

**Settings that are built into the browser files** (`VITE_APP_NAME`, `VITE_BROADCAST_DRIVER` and the `VITE_PUSHER_*` ones, see [configuration.md](configuration.md#how-settings-work)) are read from `APP_NAME`, `BROADCAST_CONNECTION` and the `PUSHER_*` settings in `.env` when the image is **built**. Change one and run `build` again; restarting is not enough.

## Where things are kept

| Volume | Holds | Lose it and |
| --- | --- | --- |
| `mysql-data` | The database | Everything is gone. Back it up. |
| `storage` | Uploads, private files (digital downloads, data exports), the API description, the maintenance flag | The files are gone and the database points at nothing. Back it up with the database. |
| `redis-data` | The queue and the sessions | Everybody is signed out and queued emails that were not sent are lost. |
| `caddy-data`, `caddy-config` | The certificates Caddy got, and its configuration | Caddy asks for new certificates, which Let's Encrypt limits. |

The names are prefixed with the project name: `metaforger-site_storage` and so on (`docker volume ls`). `docker compose -f compose.production.yaml down` keeps them; **`down -v` deletes them**.

### Backups

The database, as a plain SQL dump:

```bash
docker compose -f compose.production.yaml exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --no-tablespaces "$MYSQL_DATABASE"' > metaforger-$(date +%F).sql
```

The files, as an archive. The image has `tar`, and the container runs as root only for this, because private files are readable by their owner alone. A file uploaded during the copy may be missing from it:

```bash
docker run --rm --user root --entrypoint tar -v metaforger-site_storage:/storage:ro metaforger:latest czf - -C /storage . > storage-$(date +%F).tgz
```

Put both, and `.env`, somewhere else. A backup is something you have restored once: [upgrading.md](upgrading.md#before-you-start) says how to rehearse one on a copy. To put them back, start the `mysql` service and feed the dump to `mysql`, and unpack the archive into the volume:

```bash
docker compose -f compose.production.yaml exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < metaforger-2026-11-08.sql
docker run --rm -i --user root --entrypoint tar -v metaforger-site_storage:/storage metaforger:latest xzf - -C /storage < storage-2026-11-08.tgz
```

## Day to day

- **Logs** go to the output of each container: `docker compose -f compose.production.yaml logs -f app worker`. The site writes no files under `storage/logs`. The web server logs its own start and errors, not every request.
- **A command** in the running site: `docker compose -f compose.production.yaml exec app php artisan about` (also `schedule:list`, `queue:failed`, `tinker`). `run --rm` starts a container of its own instead, which is what the steps above use for things that must not wait for the site to be up.
- **More workers:** `docker compose -f compose.production.yaml up -d --scale worker=2`. Never more than one `scheduler`.
- **Upload sizes.** PHP in the image accepts uploads up to 110 MB (`docker/php.ini`), a little over the 100 MB that digital files are allowed by default (`COMMERCE_DOWNLOAD_MAX_KB`). Raise the setting and you raise `upload_max_filesize` and `post_max_size` in that file too, and build again.
- **Mounting a folder instead of the `storage` volume** needs the folder to be writable by user id 33 (`www-data`).

## Not covered

- **Server-side rendering** ([deployment.md](deployment.md#server-side-rendering-optional)): the image builds with `npm run build`, not `build:ssr`, and has no rendering server.
- **Meilisearch** ([search-index.md](search-index.md)): the compose file has no service for it, and the image does nothing about it. Add one yourself and set `SEARCH_DRIVER=meilisearch`.
- **PostgreSQL** in the compose file: the driver is in the image, the service is not.
- **A database or Redis outside Docker**, **several web servers**, **Kubernetes**, **Octane**, **Horizon**. Each is possible and none has been tried; [deployment.md](deployment.md#not-covered) and [redis.md](redis.md) say what changes.
- **Moving a running site into this setup:** it is the restore above, run on a dump from the old server, with the `storage` files copied into the volume, and the same `APP_KEY`. It has not been rehearsed.

## When it does not start

| You see | Cause |
| --- | --- |
| `required variable DB_PASSWORD is missing a value` | `DB_PASSWORD` or `DB_ROOT_PASSWORD` is not in `.env`. Compose reads `.env` from the folder you run it in. |
| `APP_KEY is empty` | Step 4. The message names the command. |
| `port is already allocated` or `address already in use` | Something else has the port. Set `APP_HTTP_PORT` and `APP_HTTPS_PORT`, or stop the other one. |
| `app` stays `starting` or becomes `unhealthy` | `docker compose -f compose.production.yaml logs app` says why. The health check asks `/up` on an address of the container itself. |
| The browser says the connection is not private, or no certificate comes | With a domain name: the name does not point at the machine, or ports 80 and 443 are not open to the world; Caddy's log (`logs app`) says what Let's Encrypt answered. With `localhost`: the certificate is Caddy's own, so the warning is expected. |
| Links in emails or redirects say `http://` behind a proxy | `TRUSTED_PROXIES` is not set, or `APP_URL` starts with `http://` ([above](#https)). |
| Emails do not arrive | `docker compose -f compose.production.yaml ps` should list `worker` as running; `logs worker` shows each email going out. With `MAIL_MAILER=log` (the example file) they are written to the log and sent nowhere. |
| A change to `.env` does nothing | Run `up -d` so that the containers are recreated; the site reads `.env` once, when the container starts. For a `VITE_*` setting, `build` as well. |
| The build fails while fetching fonts | The Bunny font host did not answer. Build again. |
| `Permission denied` on `storage` | A folder you mounted there is not writable by user id 33. |

## How it is tested

- The `docker` job in `.github/workflows/tests.yml` runs `node scripts/docker-smoke.mjs`. Run it yourself with Docker running (it never reads your `.env`: it uses `.env.example` plus passwords it makes up, in a folder of its own, and it takes everything down again unless you add `--keep`).
- It builds the image, starts the database and Redis, runs the installer in a container, starts the rest, and then checks: the five services are running; the administrator signs in and the member area, the admin area and Settings → Profile open ([`scripts/check-install.mjs`](../scripts/check-install.mjs)); `/.env` is not served; a PHP file put into the public storage folder is not run; a built file is cached for a year; an email that the web container queued is sent by the worker; no job has failed; the three containers stop within 45 seconds (a PHP that did not get the stop signal waits for the kill timeout, 90 seconds for the worker); and after a start the administrator can still sign in.
- Two of the Dockerfile's PHP extensions are there because of what the first run of the image found. Without `intl`, Settings → Profile failed with a 500 (the installer did not ask for the extension, and now does, and the README and `scripts/check-install.mjs` say so too). Without `pcntl`, a worker could not finish its job when it was told to stop, nor enforce its `--timeout`, and a stop took the whole 90 seconds.

| File | What it is |
| --- | --- |
| [`Dockerfile`](../Dockerfile) | Four stages: PHP with its extensions, the PHP packages, the browser files, and what runs. |
| [`compose.production.yaml`](../compose.production.yaml) | The five services and their volumes. |
| [`docker/Caddyfile`](../docker/Caddyfile) | The web server: compression, a year's cache for built files, no dot files, PHP only for `index.php`, the health check address. |
| [`docker/php.ini`](../docker/php.ini) | OPcache and upload limits. |
| [`docker/entrypoint.sh`](../docker/entrypoint.sh) | Refuses to start without an application key, and caches the configuration from the environment the container got. |
| [`.dockerignore`](../.dockerignore) | Keeps `.env`, `vendor`, `node_modules`, the tests and the documents out of the image. |
