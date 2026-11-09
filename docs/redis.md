# Running on Redis

How to move the cache, the queue and the sessions from the database to Redis for production, what lives where once you have, and what the tests hold in place.

> **Status:** the settings, the queue rule and the CI job that runs the app against a real Redis are in place. Horizon (the queue dashboard) is not installed, and [Adding Horizon](#adding-horizon) says how a site adds it. Not built: a second Redis only for the cache, Sentinel or Cluster, and a Redis card in the admin area. See [What it does not do](#what-it-does-not-do).

## Why

A new install keeps its cache, its queue and its sessions in the database. That is the right default for a laptop: there is nothing else to run. On a site with a busy forum it is the first thing to give way:

- every request reads and writes its session in a table that every other request is writing to;
- every cached page fragment, rate-limit counter and lock is a row that is read, written and pruned;
- the queue is polled by every worker, on the same database that serves the pages.

Redis does all three out of memory, and takes that traffic off the database.

## Switching

A Redis server (CI and `compose.yaml` run Redis 7) and the `redis` PHP extension (phpredis, which is `REDIS_CLIENT`'s default). Forge, Herd and most hosts have it; a Docker image needs `pecl install redis`. If you cannot have the extension, `composer require predis/predis` and `REDIS_CLIENT=predis`; the package is not installed here.

In `.env` (these three replace the `database` values that are already there; if a name appears twice in the file, the last one counts):

```dotenv
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=choose-a-long-one   # any Redis that is reachable from outside the machine needs one
REDIS_PORT=6379

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

Then run `php artisan config:cache` as usual and restart the queue workers with your process manager (`supervisorctl restart all`, for example). Not `php artisan queue:restart` this once: it signals the workers through the cache, and the running ones are still reading the old cache. Nothing needs migrating. Everyone is signed out once, because their sessions were in the database.

Switch the three together or one at a time, they do not depend on each other. If you move only the queue, jobs already waiting in the `jobs` table stay there; run `php artisan metaforger:queue work database --stop-when-empty` once to finish them.

## Where things live

| What | Setting | In Redis | Why there |
|---|---|---|---|
| Cache (pages, counts, rate limits) | `CACHE_STORE=redis` | database `REDIS_CACHE_DB` (1) | It can be emptied at any time |
| Locks (checkout, billing, Tebex) | the cache store's `lock_connection` | database `REDIS_DB` (0) | A lock that vanishes lets two requests do the same thing at once (check out one cart twice) |
| Sessions | `SESSION_DRIVER=redis` | database `REDIS_DB` (0) | Clearing the cache must not sign everybody out |
| Queue | `QUEUE_CONNECTION=redis` | database `REDIS_DB` (0), lists `queues:default` and `queues:mail` (and `queues:<REDIS_QUEUE>` if you set one) | Clearing the cache must not drop a queued email |

So `php artisan cache:clear` empties database 1 and nothing else. That is checked, against a real Redis, on every CI run (`clearing the cache leaves the sessions the queue and the locks alone`). If you set `SESSION_CONNECTION` or `REDIS_CACHE_LOCK_CONNECTION` to `cache`, that stops being true.

What stays in the database whatever you choose: failed jobs (`failed_jobs`, so a lost Redis does not lose the record of what failed) and job batches.

## The queue

A worker has to be running, with the database queue as much as with Redis: the scheduler's jobs, the queued notifications and the shop's emails go through it.

```ini
; /etc/supervisor/conf.d/metaforger-worker.conf
[program:metaforger-worker]
command=php /var/www/metaforger/artisan metaforger:queue work redis --sleep=3 --tries=3 --max-time=3600
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
```

- **`metaforger:queue work` is what makes the emails go out.** Every notification the site makes puts its email on a queue called `mail` (its database and push channels use `default`; the two that Laravel sends itself, to verify an address and to reset a password, are not queued and go out at once), and `queue:work` reads only one queue: the one `--queue` names, or else the connection's own (`queue.connections.redis.queue`: `default` unless you set `REDIS_QUEUE`; `DB_QUEUE` for the database queue). Started plainly, a worker never reads `mail`, so the emails wait in the queue for good, with the database queue as much as with Redis, and nothing fails to say so. `--queue` also *replaces* the connection's own queue instead of adding to it: a command with `--queue=default,mail` written into it stops the jobs that name no queue on a site that sets `REDIS_QUEUE=site-jobs`. `php artisan metaforger:queue work` looks the queue up. It starts `queue:work` on the connection's own queue, then `default`, then `mail` (`site-jobs,default,mail` on that site, `default,mail` on one that sets nothing), takes `queue:work`'s options as you type them, and keeps a `--queue` you give. `queue:listen` prints the list it reads when it starts, and `queue:work` does when it has a terminal. `composer dev` and the [README](../README.md) use `metaforger:queue listen`. A test (`QueueConfigTest`) fails if one of them goes back to a plain `queue:work` or `queue:listen`, or if the app starts using a queue that `App\Support\Queues\WorkerQueues` does not list. A new queue is added to the app and to that list together. A process manager that cannot run the command (a hosting panel with a queue field) needs the same list written out: `--queue=` your `REDIS_QUEUE` if you set one, then `default,mail`.
- `--max-time=3600` makes a worker finish its job and exit once an hour, so memory a job leaked is given back; Supervisor starts a new one.
- **`php artisan queue:restart` on every deploy.** Workers hold the old code in memory until they are told to stop.
- **`retry_after` must be longer than the longest a job can run.** It is 90 seconds (`REDIS_QUEUE_RETRY_AFTER`, `DB_QUEUE_RETRY_AFTER`), and a worker is stopped after 60 (`--timeout`). A job that is still running when `retry_after` passes is given to a second worker, and runs twice. A test fails if either setting drops under the worker's timeout, or if a queued class sets a timeout that reaches `retry_after`. A job that needs longer, a big export, sets `$timeout` (or `#[Timeout]`) *and* the site raises `retry_after` above it.

### A job waits for the transaction it was queued in

A database queue's row is written by the same connection as everything else, so a job queued inside a transaction does not exist until the transaction commits, and never exists if it is rolled back. A Redis list has no idea about the transaction. Without a rule, a worker could pick up "email this customer their order" before the order is saved, or send it for an order that was rolled back.

The Redis connection therefore has `after_commit` on (`config/queue.php`): a job, notification or mail pushed inside a transaction is held back until the outermost transaction commits, and dropped if it rolls back. Outside a transaction it is queued at once. `REDIS_QUEUE_AFTER_COMMIT=false` turns it off; there is no reason to.

The shop already dispatches its order events with `DB::afterCommit()`; this makes the same true of everything else.

## Adding Horizon

[Horizon](https://laravel.com/docs/13.x/horizon) is Laravel's dashboard and supervisor for Redis queues: throughput, run time and wait time for each queue, the failed jobs, and worker processes that move to the queue that is filling up. MetaForger does not include it, on purpose (decided for 1.0): the worker above runs every job, and Horizon is a package, a config file and a gate to keep up to date. A site that wants to see its queue can add it. This is what that takes in this app. It was written from Horizon's documentation and from Composer, and **not run**: Horizon is not installed here and no test covers it.

What it needs (checked with Composer on 2026-11-03: `laravel/horizon` v5.50.1, MIT, installs with Laravel 13 and PHP 8.4):

- **Redis as the queue,** `QUEUE_CONNECTION=redis`. Horizon's documentation says it does not work with Redis Cluster at this time.
- **PHP's `pcntl` and `posix` extensions.** PHP on Windows has neither, and Composer refuses to install Horizon without them, so on Windows use WSL or a container. Check a server with `php -m`.

```bash
composer require laravel/horizon
php artisan horizon:install
```

Then six things that are particular to this app:

1. **List every queue the app uses.** A supervisor reads only the queues in its `queue` option, so the list has to name `mail` as well as `default`, or the emails wait for good and nothing fails to say so (the trap described under [The queue](#the-queue)). The list is what `metaforger:queue` reads, `WorkerQueues::forConnection('redis')`: your `REDIS_QUEUE` first if you set one, then `default` and `mail`. Horizon makes a single attempt unless it is told otherwise, and the worker above makes three; `maxTime` is its `--max-time`.

   ```php
   // config/horizon.php
   'defaults' => [
       'supervisor-1' => [
           'connection' => 'redis',
           'queue' => ['default', 'mail'],   // REDIS_QUEUE first, if you set one
           'tries' => 3,
           'timeout' => 60,
           'maxTime' => 3600,
           // ...
       ],
   ],
   ```

   `QueueConfigTest` fails when the app starts using a queue that `WorkerQueues` does not list, and when a worker command skips `metaforger:queue`. It does not read `config/horizon.php`, so add a test that compares the `queue` option with `WorkerQueues::forConnection('redis')` when you add Horizon.
2. **Keep `timeout` under `retry_after`.** The supervisor's `timeout` has to be a few seconds shorter than `retry_after` (90, `REDIS_QUEUE_RETRY_AFTER`) and longer than any job's own `$timeout`. Otherwise a job that is still running is handed to a second worker and runs twice (see `retry_after` under [The queue](#the-queue)). The test that holds this reads the worker commands, not this file. In Supervisor, `stopwaitsecs` has to be longer than the longest job.
3. **Say who may open it.** `/horizon` opens in the `local` environment only; anywhere else the gate in `app/Providers/HorizonServiceProvider.php` decides. Job payloads and exceptions show what the job carried, which can be a customer's email address or an order, so keep it to administrators:

   ```php
   Gate::define('viewHorizon', fn ($user) => $user->hasRole('admin'));
   ```
4. **Give the dashboard the page's nonce.** `SecurityHeaders` sends a Content Security Policy with a nonce on every HTML response in the `web` group ([security-headers.md](security-headers.md)), and Horizon's pages are in that group. Horizon puts no nonce on its scripts and styles unless it is given one, and a script without it is blocked. Its hook is `Horizon::cspNonce()`, called from a middleware that is listed after `web` in the `middleware` option of `config/horizon.php`, so that the nonce `SecurityHeaders` made for the request exists:

   ```php
   public function handle(Request $request, Closure $next): Response
   {
       Horizon::cspNonce(Vite::cspNonce());

       return $next($request);
   }
   ```

   This is the step that has not been checked in any way. Open the dashboard with the browser's console open and make sure nothing is reported as blocked.
5. **Run it instead of the worker, not beside it.** One Supervisor program, `php artisan horizon`, which starts the workers itself (so no `numprocs=2`). Remove the `metaforger-worker` program for Redis, so that one set of workers reads each queue. On a deploy, `php artisan horizon:terminate` takes the place of `queue:restart`. `metaforger:queue` stays for the database queue, such as the jobs left behind when you move.
6. **Schedule the snapshot.** The dashboard's graphs need `horizon:snapshot` every five minutes. The schedule is in `bootstrap/app.php`: `$schedule->command('horizon:snapshot')->everyFiveMinutes();`.

Afterwards run `composer audit`, `npm run licences:check` and `npm run licences:notices`, so that `THIRD-PARTY-NOTICES.md` names the package ([licences.md](licences.md)).

## Memory and restarts

- **No eviction.** Redis starts with no memory limit and `noeviction`, which is what a queue and sessions need: when it is full a write fails loudly, instead of a queued job or somebody's session being dropped to make room. Cache entries carry a lifetime and expire on their own (one small exception, the support assignment rules, is kept until they change). Give the server room, set `maxmemory` with headroom, and watch `used_memory`. Do not use `allkeys-lru` on an instance that also holds the queue.
- **Persistence.** With nothing set, a Redis restart loses the queue and signs everybody out. Turn on the append-only file (`appendonly yes`), or use a managed Redis that has persistence. `compose.yaml` does.
- **Password and network.** A Redis with no password that anything outside the machine can reach is a way into the machine. Set `REDIS_PASSWORD`, bind to a private address, and keep the port closed. `compose.yaml` publishes Redis on `127.0.0.1` only.

## Running it locally

```bash
docker compose up -d redis
```

Then the lines under [Switching](#switching) with `REDIS_HOST=127.0.0.1`, and `php artisan metaforger:queue listen --tries=1` (or `composer dev`).

To see what you are running, `php artisan about` lists the cache, queue and session drivers.

## What the tests hold in place

| Test | Where | Runs |
|---|---|---|
| A Redis queue holds a job until the transaction commits; `retry_after` is longer than a worker's timeout and than any job's; the worker commands in `composer.json`, the README and the Supervisor example above all go through `metaforger:queue`, which reads every queue the app uses and the connection's own | `tests/Unit/QueueConfigTest.php`, `tests/Unit/QueueWorkerCommandTest.php` | with the suite |
| The cache has a database of its own; clearing it leaves sessions, queue and locks; a job waits for the commit and is never queued by a rolled back transaction; a lock is held until released; a worker runs a job and takes it off the queue | `tests/Live/Redis/RedisLiveTest.php` | the `redis (Redis 7)` CI job |

The live tests go through the real stack: a page request that starts a session, `cache:clear`, `queue:work`. `php artisan test` does not run them, and they skip themselves unless `REDIS_LIVE_HOST` is set. They **empty both Redis databases** at the end, so they will not start against a Redis that already holds a key. Use a Redis of their own:

```bash
docker run --rm -d --name metaforger-redis-live -p 6390:6379 redis:7-alpine
REDIS_LIVE_HOST=127.0.0.1 REDIS_LIVE_PORT=6390 php artisan test tests/Live/Redis
docker stop metaforger-redis-live
```

## What it does not do

- **Horizon.** It is a dashboard and a supervisor for Redis queues, and a package this app does not have. [Adding Horizon](#adding-horizon) says what a site that wants it has to do, and what it has to tell it about this app's queues and Content Security Policy.
- **Octane.** The app has been audited for a process that serves more than one request, and not run on it: see [octane.md](octane.md).
- **A second Redis for the cache.** Both connections use `REDIS_HOST`. A cache that is allowed to evict, on its own instance, is a change to `config/database.php`.
- **Sentinel, Cluster or TLS.** `REDIS_CLUSTER`, `REDIS_URL` and the rest of Laravel's options are there; none is tested.
- **Redis for rate limiting only.** Throttles use the cache store, so they move with it; `throttleWithRedis()` is not turned on.
- **The list of open sessions in Settings → Security.** It reads the `sessions` table, which only `SESSION_DRIVER=database` writes, so with Redis sessions the page shows no list and says so. What still works is **Sign out other browsers** and changing the password, which end the other sessions through the password fingerprint each session keeps and need no list ([Two-factor authentication](two-factor.md#browser-sessions)).
- **A Redis card in the admin area**, like the one for search. `php artisan about` and the server's own tools are what there is.
