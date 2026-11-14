# Configuration reference

Every setting the site reads from its environment: its name, what applies when you do not set it, and what it does. A setting that has a page of its own links to it. How to put a site on a server is [deployment.md](deployment.md); this page is the list that guide points to.

## How settings work

- **Where they live.** In the `.env` file in the project folder (`php artisan metaforger:install` makes it from `.env.example`, see [install.md](install.md)), or in the real environment of the process: a systemd `Environment=` line, a Docker `-e` (or the compose file of [docker.md](docker.md), which reads `.env` and hands all of it to the containers), the environment editor of Forge or another host. A name set in both places takes its value from the real environment.
- **Only `config/` asks.** The code reads the environment in the files of `config/` and nowhere else, and the rest of the application reads the result with `config('...')`. The one exception is the browser: settings that start with `VITE_` are written into the JavaScript by `npm run build`, so changing one means building again.
- **A cached configuration ignores `.env`.** `php artisan optimize` (on every deploy, see [performance.md](performance.md#a-production-checklist)) reads all of it once and keeps the result in `bootstrap/cache/config.php`. From then on `.env` is not read at all: **after changing a setting run `php artisan optimize` again**, and restart the queue workers, which hold the old values in memory (`php artisan queue:restart`, or your process manager). `php artisan config:show database` prints one file of the configuration as the site sees it, with the values in force.
- **Writing a value.** A line is `NAME=value`. Booleans are `true` and `false`. A value with a space, a `#` (which starts a comment) or a `$` goes in double quotes: `MAIL_FROM_NAME="My Shop"`. `${APP_NAME}` is replaced by the value of another setting. The word `null` is null; an empty value is an empty text, which for most settings below counts as not set. A list is written with commas between the items.
- **The Default column** is what applies when the name is absent from the environment. A default in `code` is checked against `config/` by the test suite (`ConfigurationDocsTest`), so it cannot go out of date. A dash means nothing is set, and words describe a default that is worked out from other settings. `.env.example` sets some values differently from the default, for a machine that is being developed on; where that matters the row says so.
- **Secrets** are marked **Secret.** They belong in `.env` or the real environment only: never in a file that is committed, never in a log, never in the browser. Keep a copy of `.env` somewhere safe ([install.md](install.md#running-it-again) says why).

## Before a site goes live

These are the settings that are different on a live site from a machine that is being developed on. The sections below say what each does.

| Setting | On a live site | Why |
| --- | --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false` | A site in debug mode shows its settings and code to anyone who makes it fail. `production` also turns on HSTS and search-engine indexing. |
| `APP_URL` | `https://` and the real address, no trailing slash | Emails, queued jobs and the sitemap build their links from it. |
| `APP_KEY` | made once, and backed up | It encrypts licence keys and two-factor secrets. A new one cannot read what the old one wrote. |
| `DB_CONNECTION` and `DB_*` | your MySQL or PostgreSQL | SQLite is for a laptop. |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS` and the `MAIL_*` it needs | a real mailer | The default writes every email to the log and sends none. |
| `SESSION_SECURE_COOKIE` | `true` | The session cookie is then sent only over HTTPS. |
| `APP_FORCE_HTTPS`, `TRUSTED_PROXIES` | set both when something other than the web server that runs PHP ends HTTPS | See [deployment.md](deployment.md#behind-a-proxy-or-a-load-balancer). |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `REDIS_*` | `redis`, with a password | See [redis.md](redis.md). |
| `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | your live keys and the signing secret of the live endpoint | Leave `STRIPE_CLI_WEBHOOK_SECRET` empty. |
| `COMMERCE_CURRENCY` | the shop's currency, before the first price is entered | |
| `SECURITY_CONTACT_URI`, `SECURITY_POLICY_URL` | where **your** site's visitors report a vulnerability | The defaults are MetaForger's own project pages. |
| `MODERATION_CONTACT` | an address or a page where a member can ask about a warning or a ban | Empty says nothing about asking. |
| `LOG_STACK`, `LOG_LEVEL` | `daily`, `warning` | The default log is one file that is never cut. |

## The application

| Key | Default | What it does |
| --- | --- | --- |
| `APP_NAME` | `Laravel` | The name of the site in emails and, through `SEO_SITE_NAME`, in page titles. It is also what the default names of the session cookie and of the cache and Redis prefixes are made from, so **renaming a live site signs everybody out**. The browser takes its copy from `VITE_APP_NAME`. `.env.example` says `MetaForger`. |
| `APP_ENV` | `production` | `production`, `staging`, `local` or `testing`. `production` turns on HSTS and indexing by default, makes the installer refuse `--demo`, and keeps the log of lazily loaded queries (which only `local` writes) off. `.env.example` says `local`. |
| `APP_DEBUG` | `false` | Shows the detailed error page, with code and settings, to whoever triggers an error. Only on your own machine. |
| `APP_KEY` | — | **Secret.** Encrypts cookies and everything the site stores encrypted (licence keys, two-factor secrets). Made by `php artisan metaforger:install` or `php artisan key:generate`. Never replace the key of a live site without putting the old one in `APP_PREVIOUS_KEYS`. |
| `APP_PREVIOUS_KEYS` | empty | **Secret.** Old application keys, separated by commas. What was encrypted under them is still read, so a key can be replaced without making stored secrets unreadable. |
| `APP_URL` | `http://localhost` | The address of the site, with `http://` or `https://` and no trailing slash. Anything made without a request uses it: queued emails, the scheduler, the console, the sitemap, the address of public uploads. |
| `APP_FORCE_HTTPS` | `false` | Makes every address the site writes start with `https://`. For a site behind a proxy that ends HTTPS, together with `TRUSTED_PROXIES`. |
| `TRUSTED_PROXIES` | — | The proxies whose `X-Forwarded-*` headers are believed: `*` or `REMOTE_ADDR` (every caller; only when the site cannot be reached except through the proxy), or the addresses and ranges of the proxies separated by commas (`10.0.0.0/8,172.16.0.0/12`). Needed when a load balancer, Cloudflare, Traefik or Caddy ends HTTPS and hands PHP plain HTTP. See [deployment.md](deployment.md#behind-a-proxy-or-a-load-balancer). |
| `APP_MAINTENANCE_DRIVER` | `file` | Where `php artisan down` keeps its flag: `file` (this server) or `cache` (shared by several servers through `APP_MAINTENANCE_STORE`). |
| `APP_MAINTENANCE_STORE` | `database` | The cache store that holds the flag when the driver is `cache`. |
| `APP_LOCALE` | `en` | The site's own language: what a visitor sees with no preference, and what a guest's order email is written in. |
| `APP_FALLBACK_LOCALE` | `en` | The language a missing translation is taken from. |
| `APP_SUPPORTED_LOCALES` | `en` | The languages the interface is offered in, separated by commas. Each needs its files under `lang/` and passes `php artisan lang:check` ([i18n.md](i18n.md)). |
| `APP_FAKER_LOCALE` | `en_US` | The language of the made-up names and text of the factories and demo seeders. |
| `BLOG_COMMENT_RATE_LIMIT` | `5` | How many blog comments one signed-in person (or one address, when not signed in) may post in a minute. |
| `IMPERSONATION_MINUTES` | `30` | How long one read-only view as a customer lasts, from the moment it starts ([impersonation.md](impersonation.md)). |
| `AUDIT_RETENTION_DAYS` | `0` | How many days the admin audit log is kept. `0` keeps it for ever ([audit-log.md](audit-log.md)). |
| `ONBOARDING_ENABLED` | `true` | Whether a new customer's dashboard shows the "Get started" checklist ([onboarding.md](onboarding.md)). |
| `ONBOARDING_DAYS` | `30` | For how many days after an account is made the checklist is shown. `0` shows it for as long as there is something left to do. |
| `MODERATION_CONTACT` | — | Where a member can ask about a warning or a ban: an email address or a web address. It is added to the emails they are sent and to what a banned member is told when they try to sign in ([moderation.md](moderation.md)). |
| `FORUM_INDEX_CACHE_TTL` | `300` | Seconds the forum's front page (the boards with their counts, the trending threads, the latest posts) is kept before it is worked out again. |

## The database

| Key | Default | What it does |
| --- | --- | --- |
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql`, `mariadb`, `pgsql` or `sqlsrv`. CI runs the suite on SQLite, MySQL 8 and PostgreSQL 16; the others are not tested. It is also the connection that keeps `failed_jobs` and job batches. |
| `DB_URL` | — | One address in place of the pieces below, for example `mysql://user:password@host:3306/name`. It takes the place of `DB_HOST` and the rest, and names the driver too. |
| `DB_HOST` | `127.0.0.1` | The database server. |
| `DB_PORT` | `3306` | Its port: `3306` for MySQL and MariaDB, `5432` for PostgreSQL. |
| `DB_DATABASE` | `laravel`; SQLite: `database/database.sqlite` | The name of the database. For SQLite, the path of the file. MySQL and PostgreSQL databases are made by you; only the SQLite file is made by the installer. |
| `DB_USERNAME` | `root` | The user the site connects as. Give it its own, with rights to this database only. |
| `DB_PASSWORD` | empty | **Secret.** |
| `DB_SOCKET` | empty | The path of a Unix socket, for a MySQL server on the same machine, in place of host and port. |
| `DB_CHARSET` | `utf8mb4` | `utf8mb4` for MySQL and MariaDB, `utf8` for PostgreSQL. |
| `DB_COLLATION` | `utf8mb4_unicode_ci` | MySQL and MariaDB only. |
| `DB_SSLMODE` | `prefer` | PostgreSQL: `disable`, `allow`, `prefer`, `require`, `verify-ca` or `verify-full`. A database on another machine wants `require` or better. |
| `MYSQL_ATTR_SSL_CA` | — | The path of the certificate authority file the site checks a MySQL server's certificate against. Managed databases need it. |
| `DB_FOREIGN_KEYS` | `true` | SQLite only: whether the database enforces foreign keys. |

## Cache, sessions and the queue

| Key | Default | What it does |
| --- | --- | --- |
| `CACHE_STORE` | `database` | `database`, `redis`, `file`, `memcached`, `dynamodb`, `array` or `null`. A live site uses `redis` ([redis.md](redis.md)). |
| `CACHE_PREFIX` | the slug of `APP_NAME`, then `-cache-` | In front of every cache key. Set it when several sites share one Redis or Memcached. |
| `DB_CACHE_CONNECTION` | — | The database connection of the `database` store (the default connection when empty). |
| `DB_CACHE_TABLE` | `cache` | Its table. |
| `DB_CACHE_LOCK_CONNECTION` | — | The connection of its lock table. |
| `DB_CACHE_LOCK_TABLE` | — | Its lock table. |
| `CACHE_STORAGE_DISK` | — | For a `storage` cache store: the filesystem disk it keeps the cache on. |
| `CACHE_STORAGE_PATH` | `framework/cache/data` | Its folder on that disk. |
| `REDIS_CACHE_CONNECTION` | `cache` | Which Redis connection the `redis` store uses. `cache` is Redis database `REDIS_CACHE_DB`. |
| `REDIS_CACHE_LOCK_CONNECTION` | `default` | Which one holds its locks. Keep it on `default`: a lock that vanishes with the cache lets two requests do the same thing at once ([redis.md](redis.md#where-things-live)). |
| `MEMCACHED_HOST` | `127.0.0.1` | The Memcached server. Needs the `memcached` PHP extension. |
| `MEMCACHED_PORT` | `11211` | Its port. |
| `MEMCACHED_USERNAME` | — | **Secret.** |
| `MEMCACHED_PASSWORD` | — | **Secret.** |
| `MEMCACHED_PERSISTENT_ID` | — | A name that makes the connection last between requests. |
| `DYNAMODB_CACHE_TABLE` | `cache` | The DynamoDB table of the `dynamodb` store. It uses the `AWS_*` settings. |
| `DYNAMODB_ENDPOINT` | — | A DynamoDB-compatible address, for a local one. |
| `SESSION_DRIVER` | `database` | `database`, `redis`, `file`, `cookie`, `memcached`, `dynamodb` or `array`. A live site uses `redis`. The list of open sessions in Settings → Security needs `database`; with another driver the page shows no list ([two-factor.md](two-factor.md#browser-sessions)). |
| `SESSION_LIFETIME` | `120` | Minutes without a request after which a session ends. |
| `SESSION_EXPIRE_ON_CLOSE` | `false` | End the session when the browser is closed. |
| `SESSION_ENCRYPT` | `false` | Encrypt what a session holds. |
| `SESSION_CONNECTION` | — | The database or Redis connection of the sessions. Keep sessions on `default`: clearing the cache must not sign everybody out. |
| `SESSION_TABLE` | `sessions` | The table of the `database` driver. |
| `SESSION_STORE` | — | The cache store of a driver that uses one. |
| `SESSION_COOKIE` | the slug of `APP_NAME`, then `-session` | The name of the session cookie. |
| `SESSION_PATH` | `/` | The path the cookie is sent for. |
| `SESSION_DOMAIN` | — | The domain the cookie is sent to, for sharing it with subdomains (`.example.com`). `.env.example` says `null`. |
| `SESSION_SECURE_COOKIE` | — | Send the cookie only over HTTPS. Unset means `false`. **Set it to `true` on a live site.** |
| `SESSION_HTTP_ONLY` | `true` | Hide the cookie from JavaScript. |
| `SESSION_SAME_SITE` | `lax` | `lax`, `strict`, `none` or `null`. |
| `SESSION_PARTITIONED_COOKIE` | `false` | Tie the cookie to the site it was set from (CHIPS). Needs `SESSION_SECURE_COOKIE` and `none`. |
| `QUEUE_CONNECTION` | `database` | `database`, `redis`, `sqs`, `beanstalkd`, `sync` or `null`. A worker has to be running whichever it is ([redis.md](redis.md#the-queue)). |
| `DB_QUEUE_CONNECTION` | — | The database connection of the `database` queue. |
| `DB_QUEUE_TABLE` | `jobs` | Its table. |
| `DB_QUEUE` | `default` | The queue a worker reads on that connection besides `default` and `mail`. |
| `DB_QUEUE_RETRY_AFTER` | `90` | Seconds before a job that has not finished is given to another worker. It has to be longer than the longest a job can run. |
| `REDIS_QUEUE_CONNECTION` | `default` | The Redis connection of the `redis` queue. |
| `REDIS_QUEUE` | `default` | The queue a worker reads on that connection besides `default` and `mail`. |
| `REDIS_QUEUE_RETRY_AFTER` | `90` | As `DB_QUEUE_RETRY_AFTER`. |
| `REDIS_QUEUE_AFTER_COMMIT` | `true` | Hold a job back until the transaction it was queued in commits. There is no reason to turn it off ([redis.md](redis.md#a-job-waits-for-the-transaction-it-was-queued-in)). |
| `BEANSTALKD_QUEUE_HOST` | `localhost` | The Beanstalkd server. Needs `pda/pheanstalk`. |
| `BEANSTALKD_QUEUE` | `default` | Its queue. |
| `BEANSTALKD_QUEUE_RETRY_AFTER` | `90` | As above. |
| `SQS_PREFIX` | `https://sqs.us-east-1.amazonaws.com/your-account-id` | The address that queue names are added to. Amazon SQS also uses the `AWS_*` settings and needs `aws/aws-sdk-php`. |
| `SQS_QUEUE` | `default` | Its queue. |
| `SQS_SUFFIX` | — | Added to the end of a queue name. |
| `QUEUE_FAILED_DRIVER` | `database-uuids` | Where failed jobs are recorded: `database-uuids`, `database`, `dynamodb` or `null`. |

## Redis

All three of the cache, the sessions and the queue can use it ([redis.md](redis.md)). The extension is `redis` (phpredis); the other client is Predis, which is not installed.

| Key | Default | What it does |
| --- | --- | --- |
| `REDIS_CLIENT` | `phpredis` | `phpredis` or `predis`. |
| `REDIS_CLUSTER` | `redis` | Predis only, and only for a Redis Cluster: `redis` lets the servers spread the keys, `predis` has the client do it. |
| `REDIS_PREFIX` | the slug of `APP_NAME`, then `-database-` | In front of every key. |
| `REDIS_PERSISTENT` | `false` | Keep connections open between requests. |
| `REDIS_URL` | — | One address in place of the pieces below: `redis://user:password@host:6379`. |
| `REDIS_HOST` | `127.0.0.1` | The Redis server. |
| `REDIS_USERNAME` | — | For Redis 6 access control lists. |
| `REDIS_PASSWORD` | — | **Secret.** Any Redis that can be reached from outside the machine needs one. `.env.example` says `null`. |
| `REDIS_PORT` | `6379` | Its port. |
| `REDIS_DB` | `0` | The database that holds the sessions, the queue and the locks. |
| `REDIS_CACHE_DB` | `1` | The database that holds the cache, so that `php artisan cache:clear` leaves the rest alone. |
| `REDIS_MAX_RETRIES` | `3` | How many times a failed command is tried again. |
| `REDIS_BACKOFF_ALGORITHM` | `decorrelated_jitter` | How the wait between tries grows (phpredis): `default`, `decorrelated_jitter`, `equal_jitter`, `exponential`, `uniform` or `constant`. |
| `REDIS_BACKOFF_BASE` | `100` | The first wait, in milliseconds. |
| `REDIS_BACKOFF_CAP` | `1000` | The longest wait, in milliseconds. |

## Sign-in, API keys and security

| Key | Default | What it does |
| --- | --- | --- |
| `AUTH_MUST_VERIFY_EMAIL` | `false` | Whether a member must confirm their email address before they can use the site. This is only the starting value: once an administrator saves **Email verification** under **System settings**, that choice is used instead. |
| `AUTH_GUARD` | `web` | The guard a request is signed in with. Do not change it. |
| `AUTH_PASSWORD_BROKER` | `users` | The password reset settings used. Do not change it. |
| `AUTH_MODEL` | the model `App\Models\User` | The model of a member. Do not change it. |
| `AUTH_PASSWORD_RESET_TOKEN_TABLE` | `password_reset_tokens` | The table of reset tokens. |
| `AUTH_PASSWORD_TIMEOUT` | `10800` | Seconds after which a signed-in member is asked for their password again before something sensitive: turning two-factor off and making new recovery codes ([Turning it off](two-factor.md#turning-it-off)). |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost`, `127.0.0.1`, `::1` and the host of `APP_URL` | The hosts whose browser requests to `/api` are signed in by the member's session instead of an API key. Needed only for a separate front end on another host that calls the API with the member's session; API keys work without it. A list, with ports where the host has one. `.env.example` lists only the local ones. |
| `SANCTUM_TOKEN_PREFIX` | empty | Put in front of new API keys. A distinctive prefix lets GitHub's secret scanning recognise a key that was leaked ([api-keys.md](api-keys.md)). |
| `API_KEYS_MAX` | `10` | How many keys that can still be used one person may have at a time ([api-keys.md](api-keys.md)). Revoked and expired keys do not count. |
| `API_KEYS_ALLOW_NO_EXPIRY` | `true` | Whether a person may make a key that never expires. |
| `API_SIGN_IN_SCOPES` | `profile:read` | The scopes of a key made by `POST /api/v1/auth/token` when the request does not ask for any: a list, or `*` for every scope. |
| `API_SIGN_IN_EXPIRY_DAYS` | `90` | How long that key lasts. Empty or `0` is no end, where `API_KEYS_ALLOW_NO_EXPIRY` allows it. |
| `CSP_ENABLED` | `true` | Send the Content Security Policy ([security-headers.md](security-headers.md)). |
| `CSP_REPORT_ONLY` | `false` | Send it as report-only: the browser reports what it would have blocked and blocks nothing. For trying a change. |
| `CSP_REPORT_URI` | — | Where browsers send those reports. |
| `SECURITY_HSTS` | `true` in production, otherwise `false` | Send `Strict-Transport-Security`, over HTTPS only. Turn it on only when the whole site, and the subdomains if you include them, is served over HTTPS. |
| `SECURITY_HSTS_MAX_AGE` | `31536000` | How many seconds a browser remembers to use HTTPS: one year. |
| `SECURITY_HSTS_SUBDOMAINS` | `false` | Add `includeSubDomains`. |
| `SECURITY_CONTACT_URI` | `https://github.com/MetaGrenade/laravel-vue/security/advisories/new` | Where a vulnerability is reported, published at `/.well-known/security.txt` ([security-contact.md](security-contact.md)). **A site that runs MetaForger sets its own.** |
| `SECURITY_POLICY_URL` | `https://github.com/MetaGrenade/laravel-vue/security/policy` | The page that describes how it is handled. Set your own too. |

## Mail

Every notification is queued on `mail`, so a queue worker must be running ([redis.md](redis.md#the-queue)). How the emails look is [email.md](email.md).

| Key | Default | What it does |
| --- | --- | --- |
| `MAIL_MAILER` | `log` | `smtp`, `sendmail`, `ses`, `postmark`, `resend`, `mailgun`, `log` or `array`. The default writes each email to the log and sends nothing. `smtp` and `sendmail` need nothing more; Postmark, Resend, Mailgun and Amazon SES each need a Composer package that is not installed (Laravel's mail documentation names it: for example `symfony/postmark-mailer` with `symfony/http-client` for Postmark). |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | The address emails are sent from. Use one your mail service is allowed to send for. |
| `MAIL_FROM_NAME` | the value of `APP_NAME` | The name emails are sent from. |
| `MAIL_SCHEME` | — | `smtp` or `smtps` (TLS from the first byte). Empty lets the port decide: 465 is TLS. `.env.example` says `null`. |
| `MAIL_URL` | — | One address in place of the pieces: `smtp://user:password@host:587`. |
| `MAIL_HOST` | `127.0.0.1` | The SMTP server. |
| `MAIL_PORT` | `2525` | Its port. |
| `MAIL_USERNAME` | — | The SMTP user. `.env.example` says `null`. |
| `MAIL_PASSWORD` | — | **Secret.** `.env.example` says `null`. |
| `MAIL_EHLO_DOMAIN` | the host of `APP_URL` | The name the site gives the SMTP server when it greets it. |
| `MAIL_SENDMAIL_PATH` | `/usr/sbin/sendmail -bs -i` | The command of the `sendmail` mailer. |
| `MAIL_LOG_CHANNEL` | — | The log channel the `log` mailer writes to (the default channel when empty). |
| `POSTMARK_API_KEY` | — | **Secret.** The Postmark mailer's key. |
| `RESEND_API_KEY` | — | **Secret.** The Resend mailer's key. |
| `MAIL_BRAND_COLOR` | `#4f46e5` | The colour of the buttons and links in an email. |
| `MAIL_LOGO_URL` | — | The address of a logo image for the top of an email. Empty shows the first letter of the name in a badge. |
| `MAIL_FOOTER` | — | A line under every email, such as a company name and address. |
| `SLACK_BOT_USER_OAUTH_TOKEN` | — | **Secret.** Laravel's Slack notification channel. MetaForger sends nothing through it by itself; the setting is for code you add. |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | — | Its default channel. |

## Files and uploads

Public pictures (blog covers, product pictures) are served from `storage/app/public`, linked into `public/storage` by `php artisan storage:link`. Support attachments, forum attachments and digital downloads are on private disks and are only handed out through routes that check who is asking ([private-uploads.md](private-uploads.md): a public disk is refused for them).

| Key | Default | What it does |
| --- | --- | --- |
| `FILESYSTEM_DISK` | `local` | The disk code uses when it does not name one. |
| `PUBLIC_DISK_URL` | `APP_URL` and `/storage` | Where the web finds the public disk. Set it to put a CDN in front of the pictures ([images.md](images.md#using-a-cdn)). |
| `COMMERCE_IMAGE_DISK` | `public` | The disk of product pictures. It has to be served by the web. |
| `COMMERCE_DOWNLOAD_DISK` | `local` | The disk of digital downloads. It has to be private. |
| `FORUM_ATTACHMENT_DISK` | `local` | The disk of forum attachments ([forum-attachments.md](forum-attachments.md)). Private. |
| `SUPPORT_ATTACHMENT_DISK` | `local` | The disk of support-ticket attachments ([support-attachments.md](support-attachments.md)). Private. |
| `AWS_ACCESS_KEY_ID` | — | **Secret.** One key for everything that uses Amazon: an S3 disk, SQS, DynamoDB and SES. |
| `AWS_SECRET_ACCESS_KEY` | — | **Secret.** |
| `AWS_DEFAULT_REGION` | `us-east-1` | The region. |
| `AWS_BUCKET` | — | The S3 bucket. An S3 disk needs `league/flysystem-aws-s3-v3`, which is not installed. |
| `AWS_URL` | — | The public address of the bucket (a CDN's, for example). |
| `AWS_ENDPOINT` | — | An S3-compatible service's address (Cloudflare R2, MinIO, DigitalOcean Spaces). |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | `true` for services that want `host/bucket` rather than `bucket.host`. |

## Logs

| Key | Default | What it does |
| --- | --- | --- |
| `LOG_CHANNEL` | `stack` | The channel the site logs to. `stack` writes to every channel in `LOG_STACK`. |
| `LOG_STACK` | `single` | The channels of the stack, separated by commas. `single` is one file, `storage/logs/laravel.log`, that is never cut; `daily` starts a new file each day and deletes old ones. |
| `LOG_LEVEL` | `debug` | The least serious message that is written: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert` or `emergency`. A live site wants `warning` or `error`. |
| `LOG_DAILY_DAYS` | `14` | How many days of the `daily` channel are kept. |
| `LOG_DEPRECATIONS_CHANNEL` | `null` | Where warnings about code that will stop working are written. |
| `LOG_DEPRECATIONS_TRACE` | `false` | Include the stack trace of each. |
| `LOG_SLACK_WEBHOOK_URL` | — | **Secret.** The incoming webhook of the `slack` channel. |
| `LOG_SLACK_USERNAME` | the value of `APP_NAME` | The name it posts as. |
| `LOG_SLACK_EMOJI` | `:boom:` | Its icon. |
| `LOG_PAPERTRAIL_HANDLER` | the UDP syslog handler | The handler of the `papertrail` channel. |
| `PAPERTRAIL_URL` | — | Its host. |
| `PAPERTRAIL_PORT` | — | Its port. |
| `LOG_STDERR_FORMATTER` | — | The formatter of the `stderr` channel (a class name). |
| `LOG_SYSLOG_FACILITY` | PHP's `LOG_USER` | The facility of the `syslog` channel. |

## Realtime

Notifications are pushed to the browser through Pusher or a service that speaks its protocol ([Live updates](realtime.md)). With no credentials the site logs the broadcast and pushes nothing, and everything else works.

| Key | Default | What it does |
| --- | --- | --- |
| `BROADCAST_CONNECTION` | — | `pusher`, `ably`, `redis`, `log` or `null`. Left out, or `log`, the site uses `pusher` when `PUSHER_APP_ID`, `PUSHER_APP_KEY` and `PUSHER_APP_SECRET` are all set, and `log` when they are not. `pusher` without all three is `log` too. |
| `BROADCAST_FORCE_LOG` | `false` | `true` keeps `log` whatever `BROADCAST_CONNECTION` says and whichever credentials are set: for a staging site that has the production Pusher keys in its `.env` and must not push to real people. Anything but a true value (`true`, `1`, `on`, `yes`) leaves it off. Check with `php artisan config:show broadcasting.default`. |
| `PUSHER_APP_ID` | — | The app's id. |
| `PUSHER_APP_KEY` | — | Its key. It is public: the browser gets it as `VITE_PUSHER_APP_KEY`. |
| `PUSHER_APP_SECRET` | — | **Secret.** |
| `PUSHER_APP_CLUSTER` | — | The cluster, for example `mt1`. |
| `PUSHER_HOST` | — | A host of your own (a Pusher-compatible server) in place of Pusher's. The browser only connects where the Content Security Policy allows: add the host to `connect-src` ([security-headers.md](security-headers.md#adding-a-third-party-host)). |
| `PUSHER_PORT` | — | Its port. Empty is 443 for `https` and 80 for `http`. |
| `PUSHER_SCHEME` | `https` | `https` or `http`. |
| `PUSHER_FORCE_TLS` | — | Use TLS whatever the scheme says. Empty follows the scheme. |
| `PUSHER_VERIFY_SSL` | — | `false` skips checking the server's certificate, for a self-signed one in development. |
| `PUSHER_CA_BUNDLE` | — | The path of a certificate authority file to check it against. |
| `ABLY_KEY` | — | **Secret.** For the `ably` connection, which needs `ably/ably-php`. |
| `VITE_APP_NAME` | `Laravel` | The name in the browser's page titles. `.env.example` sets it to `${APP_NAME}`. Built into the JavaScript. |
| `VITE_BROADCAST_DRIVER` | `pusher` | Set to anything else to switch off the browser's connection. Built into the JavaScript. |
| `VITE_PUSHER_APP_KEY` | — | The key the browser connects with. Without it the browser does not connect, and does not download the client. `.env.example` sets it to `${PUSHER_APP_KEY}`. |
| `VITE_PUSHER_APP_CLUSTER` | `mt1` | The cluster the browser connects to. |
| `VITE_PUSHER_HOST` | `ws-` and the cluster, then `.pusher.com` | The host the browser connects to. |
| `VITE_PUSHER_PORT` | `443` with `https`, `80` otherwise | Its port. |
| `VITE_PUSHER_SCHEME` | `https` | `https` or `http`. |
| `VITE_PUSHER_FORCE_TLS` | follows `VITE_PUSHER_SCHEME` | Use TLS whatever the scheme says. |

## Search

`database` searches with `LIKE` and needs nothing; `meilisearch` searches an index ([search-index.md](search-index.md)).

| Key | Default | What it does |
| --- | --- | --- |
| `SEARCH_DRIVER` | `database` | `database` or `meilisearch`. |
| `MEILISEARCH_HOST` | `http://localhost:7700` | The address of Meilisearch. `.env.example` says `http://127.0.0.1:7700`. |
| `MEILISEARCH_KEY` | — | **Secret.** A key limited to what the site needs, not the master key ([search-index.md](search-index.md#keys)). It is never sent to the browser. |
| `SCOUT_QUEUE` | `true` with Meilisearch, otherwise `false` | Whether saving a record indexes it on the queue (a worker must be running) or while the request is handled. On the queue a save still works while Meilisearch is down. |
| `SEARCH_MEILISEARCH_CIRCUIT_SECONDS` | `60` | How long search falls back to the database after Meilisearch could not be reached, instead of trying on every request. |
| `SEARCH_MEILISEARCH_CIRCUIT_CACHE_KEY` | `search:meilisearch:circuit_open` | The cache key that remembers it. |
| `SEARCH_HEALTH_TIMEOUT` | `3` | Seconds the admin health card and `metaforger:search:health` wait for Meilisearch. |
| `SEARCH_REINDEX_WAIT` | `120` | Seconds `metaforger:search:reindex` waits for Meilisearch to finish before it says the rebuild is done. A large index needs longer; `--wait=0` does not wait. |
| `SEARCH_HIGHLIGHT_CONTEXT_CHARS` | `80` | How long, in characters, the excerpt around the match in a result is. |
| `SEARCH_QUERY_MIN_LENGTH` | `2` | The shortest search that is written to the search log. A shorter one is still carried out. |
| `SEARCH_QUERY_RETENTION_DAYS` | `90` | How many days a logged search is kept. |
| `SEARCH_QUERY_AGGREGATION_WINDOW_DAYS` | `30` | How many days of logged searches the daily job adds up for the admin statistics. `0` adds up all of them. |
| `SEARCH_TOP_QUERIES_LIMIT` | `5` | How many of the most frequent searches the statistics list. |
| `SEARCH_TOP_ZERO_QUERIES_LIMIT` | `5` | How many of the searches that found nothing. |

## The shop

The shop is [commerce.md](commerce.md). Taking payment needs Stripe or Tebex, a queue worker and the scheduler; [payments.md](payments.md) is the way through it, from test keys to going live.

| Key | Default | What it does |
| --- | --- | --- |
| `COMMERCE_CURRENCY` | the value of `CASHIER_CURRENCY`, in capitals | The one currency the shop sells in. Set it before the first price is entered. With Tebex's Checkout API it has to be the store's currency. |
| `COMMERCE_PROVIDER` | `stripe` | `stripe` or `tebex`: the store-level payment provider. An administrator can choose another under **System settings**, and that choice is used instead. |
| `COMMERCE_GUEST_CHECKOUT` | `true` | Allow buying without an account. |
| `COMMERCE_PAYMENT_WINDOW` | `60` | Minutes stock is held for an unpaid order before it is released. At least 30, because Stripe Checkout sessions cannot expire sooner. |
| `COMMERCE_ORDER_PREFIX` | `MF` | The start of the order number, as in `MF-000123`. |
| `COMMERCE_LOW_STOCK_THRESHOLD` | `5` | Tracked stock at or below this is flagged as low in the admin area. |
| `COMMERCE_IMAGE_MAX_PIXELS` | `16000000` | The largest product picture accepted, as width times height. 16 million is a 4000 by 4000 photo. It also applies to blog covers and to forum and support pictures. With the GD that comes with PHP, `memory_limit` can allow fewer: with 128 MB, about 8 million ([images.md](images.md#how-much-memory-a-picture-needs)). |
| `COMMERCE_DOWNLOAD_MAX_KB` | `102400` | The largest digital file, in kilobytes (100 MB). PHP's `upload_max_filesize` and `post_max_size` must allow it too. |
| `COMMERCE_DOWNLOAD_LIMIT` | `10` | How many times a customer can download each file of an order. `0` is no limit. |
| `COMMERCE_DOWNLOAD_EXPIRY_DAYS` | `0` | How many days after payment downloads stop working. `0` is never. |
| `COMMERCE_DOWNLOAD_LINK_MINUTES` | `30` | How long a download link on the order page works. The page makes a new one each time it is opened. |
| `COMMERCE_WISHLIST_MAX` | `200` | The most products one customer can save. |
| `COMMERCE_REVIEWS` | `true` | Allow and show product reviews. |
| `COMMERCE_REVIEWS_REQUIRE_PURCHASE` | `false` | Only a customer who bought the product can review it. |
| `COMMERCE_REVIEWS_APPROVAL` | `unverified` | Which reviews wait for staff to publish them: `all`, `unverified` (those not from a buyer) or `none`. |
| `COMMERCE_GIFT_CARDS` | `true` | Accept gift cards at checkout. `false` hides the box; the cards and their balances are kept. |

## Stripe and subscriptions

Subscriptions and the shop's Stripe checkout are [billing.md](billing.md) and [commerce.md](commerce.md). The endpoint is `POST /stripe/webhook`.

| Key | Default | What it does |
| --- | --- | --- |
| `STRIPE_KEY` | — | The publishable key (`pk_...`). It is meant to be seen by the browser. |
| `STRIPE_SECRET` | — | **Secret.** The secret key (`sk_...`). Stripe is offered at checkout only when this and `STRIPE_WEBHOOK_SECRET` are set. |
| `STRIPE_WEBHOOK_SECRET` | — | **Secret.** The signing secret (`whsec_...`) of the endpoint you added in the Stripe Dashboard. |
| `STRIPE_CLI_WEBHOOK_SECRET` | — | **Secret.** The signing secret that `stripe listen` prints, accepted in addition to the one above. For a development machine: **leave it empty on a live site**, because anybody who has run the CLI knows a secret that would sign a payment. |
| `STRIPE_WEBHOOK_TOLERANCE` | `300` | Seconds a signature may be older, or ahead, before it is refused. `0` accepts any time. |
| `STRIPE_TIMEOUT` | `15` | Seconds a call to Stripe waits for an answer before it is given up on and fails (the library's own is 80, longer than the 60 seconds a queue worker lets a job run). `0` waits for ever. See [performance.md](performance.md#stripe-calls-that-do-not-come-back). |
| `STRIPE_CONNECT_TIMEOUT` | `5` | Seconds a call to Stripe waits to make the connection (the library's own is 30). `0` waits for ever. |
| `STRIPE_PRICE_STARTER` | `price_starter` | The Stripe price id of the example Starter plan. They only work once you have made the prices in Stripe. Plans can also be made in the admin area. |
| `STRIPE_PRICE_PRO` | `price_pro` | The Stripe price id of the example Pro plan. |
| `STRIPE_PORTAL_CONFIGURATION` | — | The id (`bpc_...`) of one of several customer portal configurations. Needed only if you made more than one. |
| `BILLING_PORTAL_ENABLED` | `true` | Show the button that sends a customer to Stripe's billing portal. Its settings are saved in the Stripe Dashboard first, once in each mode ([billing.md](billing.md#customer-portal)). |
| `BILLING_SUBSCRIPTION_NAME` | `default` | The name subscriptions are kept under. Do not change it on a site that has subscribers. |
| `BILLING_INVOICES_PER_PAGE` | `25` | Invoices shown on a page of the billing settings. |
| `BILLING_WEBHOOK_STORE` | `true` | Keep the payload of each Stripe event, so that it can be looked at and run again under **Billing → Webhooks** ([webhooks.md](webhooks.md)). |
| `BILLING_USAGE_RETENTION_MONTHS` | `13` | How many months of metered usage are kept ([usage.md](usage.md)). |
| `BILLING_API_FREE_ALLOWANCE` | — | How many API calls a month an account whose plans give none may make. Empty counts them without limiting. |
| `BILLING_API_OVERAGE` | `block` | What happens at the allowance: `block` refuses the request with a 429, `allow` goes on counting (for when you bill the extra yourself). |
| `CASHIER_CURRENCY` | `usd` | The currency of subscriptions. `COMMERCE_CURRENCY` follows it when it is not set. |
| `CASHIER_CURRENCY_LOCALE` | `en` | The language money is written in on invoices. |
| `CASHIER_PATH` | `billing` | The path prefix of Cashier's own routes. |
| `CASHIER_MODEL` | the model `App\Models\User` | The billable model. Do not change it. |
| `CASHIER_PAYMENT_NOTIFICATION` | none | The notification sent to a customer when a renewal needs them to confirm the payment with their bank: the name of a notification class, for example `Laravel\Cashier\Notifications\ConfirmPayment`, which emails a link to Cashier's confirmation page. Unset, nobody is sent anything (the failed payment is dealt with by `invoice.payment_failed` in any case). Anything that is not the name of an existing class, such as `true`, sends nothing and writes a warning to the log. |
| `CASHIER_LOGGER` | — | A log channel for Cashier's own messages. |

## Tebex

Selling through a Tebex store is [tebex.md](tebex.md).

| Key | Default | What it does |
| --- | --- | --- |
| `TEBEX_PUBLIC_TOKEN` | — | The store's public token, from **Developers → API Keys** in the Tebex panel. It is not secret. Nothing works without it. |
| `TEBEX_PRIVATE_KEY` | — | **Secret.** The store's private key, from the same page. Used on the server only. |
| `TEBEX_WEBHOOK_SECRET` | — | **Secret.** The secret of the webhook endpoint (**Developers → Webhooks → Endpoints**), pointed at `/webhooks/tebex`. Without it an order paid through Tebex is never marked paid. |
| `TEBEX_WEBHOOK_CHECK_IP` | `true` | Accept webhooks only from Tebex's two addresses. Turn it off only when a proxy hides the sender's address and cannot be told to pass it on; the signature is still checked. |
| `TEBEX_SYNC_EVERY_MINUTES` | `60` | How often the scheduler refreshes the local copy of the catalogue. At least 5; `0` turns the schedule off (the command and the admin button still work). |
| `TEBEX_CHECKOUT_MODE` | `redirect` | Where the buyer pays: `redirect` (Tebex's own page), `popup` or `inline` (Tebex.js on the shop's page, which lets Tebex's frame in through the Content Security Policy). |
| `TEBEX_CHECKOUT_THEME` | `default` | The theme of Tebex.js: `auto`, `default` (the store's setting), `light` or `dark`. |
| `TEBEX_BASKET_LOCK_WAIT` | `10` | Seconds a request waits for another that is changing the same basket, then gives up without touching it. Needs a cache that supports locks. |
| `TEBEX_HTTP_TIMEOUT` | `10` | Seconds to wait for an answer from Tebex. |
| `TEBEX_HTTP_CONNECT_TIMEOUT` | `5` | Seconds to wait for a connection. |
| `TEBEX_API_URL` | `https://headless.tebex.io/api` | Tebex's Headless API. Change it only to point at a test double. |
| `TEBEX_CHECKOUT_API_ENABLED` | `false` | **Experimental.** Sell the shop's own products through Tebex's Checkout API in place of Tebex's packages. Needs Tebex's approval, and has never run against a live account. |
| `TEBEX_PROJECT_ID` | — | The project id the Checkout API signs in with (not the public token). |
| `TEBEX_CHECKOUT_API_URL` | `https://checkout.tebex.io/api` | The Checkout API. |

## Sign in with Google, Discord and Steam

See [Signing in with Google, Discord and Steam](social-login.md). The address each provider sends the person back to has to be registered with the provider exactly as written here.

| Key | Default | What it does |
| --- | --- | --- |
| `GOOGLE_CLIENT_ID` | — | The OAuth client of the Google button. The button is offered only when this and the secret are set. |
| `GOOGLE_CLIENT_SECRET` | — | **Secret.** Needed with the client id. |
| `GOOGLE_REDIRECT_URI` | — | Optional. `.env.example` sets `${APP_URL}/auth/oauth/google/callback`, and the site uses that address itself when this is left out. |
| `DISCORD_CLIENT_ID` | — | The application of the Discord button. The button is offered only when this and the secret are set. |
| `DISCORD_CLIENT_SECRET` | — | **Secret.** Needed with the client id. |
| `DISCORD_REDIRECT_URI` | — | Optional. `.env.example` sets `${APP_URL}/auth/oauth/discord/callback`, and the site uses that address itself when this is left out. |
| `STEAM_API_KEY` | — | **Secret.** A Steam Web API key. The Steam button is offered only when it is set. Steam's return address is built from `APP_URL`, and Steam wants the domain added in its dashboard to match. |

## The Discord companion

The bot, role sync and notifications of `metagrenade/metaforge-discord` ([discord-companion.md](discord-companion.md)). Nothing happens until the token, the guild and the shared secret are set.

| Key | Default | What it does |
| --- | --- | --- |
| `DISCORD_BOT_TOKEN` | — | **Secret.** The token of the Discord bot. |
| `DISCORD_GUILD_ID` | — | The id of the Discord server. |
| `DISCORD_APPLICATION_ID` | — | The id of the Discord application. |
| `DISCORD_BOT_SHARED_SECRET` | — | **Secret.** Signs the requests between the bot and the site. The gateway bot is given the same value. |
| `DISCORD_WEBHOOK_MAX_SKEW` | `300` | Seconds a signed request from the bot may be old. |
| `DISCORD_WEBHOOK_URL` | — | **Secret.** A Discord webhook that notifications and mirrored tickets go to when nothing names another. |
| `DISCORD_API_BASE` | `https://discord.com/api/v10` | Discord's API. Change it only to point at a test double. |
| `DISCORD_ROLE_SYNC_ENABLED` | `true` | Give members the Discord roles that match their roles on the site. |
| `DISCORD_PRESERVE_UNMAPPED_ROLES` | `true` | Leave alone the Discord roles that no role of the site maps to. |
| `DISCORD_STALE_SYNC_HOURS` | `24` | After how many hours a member who has not been synchronised is done again. |
| `DISCORD_SCHEDULE_STALE_SYNC` | `true` | Let the scheduler do that. |
| `DISCORD_SOCIAL_PROVIDER` | `discord` | The sign-in provider whose linked accounts are the Discord members. |
| `DISCORD_ADMIN_ROUTE_PREFIX` | `discord/admin` | The address of the staff pages. `config/impersonation.php` names those pages by their address, so a different prefix has to be changed there as well. |
| `DISCORD_USER_ROUTE_PREFIX` | `discord/user` | The address of the members' pages. |
| `DISCORD_SUPPORT_TICKET_EVENT_CLASSES` | `App\Events\SupportTicketDiscordMirror` | Events that mirror a support ticket to Discord, as class names separated by commas. |
| `DISCORD_FORUM_CROSSPOST_EVENT_CLASSES` | `App\Events\ForumPostCreated` | Events that cross-post a forum thread to Discord. |
| `DISCORD_MODERATION_MIRROR_ENABLED` | `true` | The master switch of the staff mirror: a message in a private Discord channel when a moderator records a warning, ban, unban or removal ([discord-companion.md](discord-companion.md#moderation-staff-mirror)). |
| `DISCORD_MODERATION_EVENT_CLASSES` | `App\Events\ModerationDiscordMirror` | The events the companion listens to for that mirror, as class names separated by commas. |
| `DISCORD_MODERATION_CHANNEL_ID` | — | The private staff channel the bot posts to. |
| `DISCORD_MODERATION_WEBHOOK_URL` | — | **Secret.** A Discord webhook to post to instead, when there is no bot. |
| `DISCORD_MODERATION_URL_TEMPLATE` | — | The link in the staff message to the member's moderation page. `{user_id}` and `{action_id}` stand for the member and the action; `.env.example` shows `${APP_URL}/acp/users/{user_id}/moderation` in a comment. |
| `DISCORD_MODERATION_STAFF_REASON_IN_EMBED` | `false` | Put a shortened copy of the reason the member was shown in the staff message. Off, it holds only the action's id and type and the two accounts. |
| `DISCORD_MODERATION_REASON_EXCERPT_MAX` | `200` | How many characters of that reason are kept. |
| `DISCORD_MODERATION_SUBJECT_LABEL_MAX` | `120` | How many characters of the title of the thread or post an action was about are kept. |
| `DISCORD_USER_MODEL` | `App\Models\User` | The member model. Do not change it. |
| `DISCORD_SOCIAL_ACCOUNT_MODEL` | `App\Models\SocialAccount` | The model of a linked account. Do not change it. |

## Search engines and social cards

Titles, descriptions and the sitemap ([Search engines and sharing](seo.md)).

| Key | Default | What it does |
| --- | --- | --- |
| `SEO_SITE_NAME` | the value of `APP_NAME` | The name added to every page title. |
| `SEO_DESCRIPTION` | MetaForger's own description | The description of a page that has none. |
| `SEO_HOME_TITLE` | MetaForger's own title | The title of the home page. The site name is added. |
| `SEO_HOME_DESCRIPTION` | MetaForger's own description | The description of the home page. |
| `SEO_IMAGE` | — | The social share image (1200 by 630): a full address, or a path from `APP_URL`. |
| `SEO_TWITTER_HANDLE` | — | The `@name` of the site's account on Twitter / X. |
| `SEO_LOCALE` | `en_US` | The language of the page for Open Graph. |
| `SEO_INDEXING` | `true` in production, otherwise `false` | Whether search engines may index the site. `false` makes `robots.txt` block everything and marks every page `noindex`: right for staging. Account, admin and sign-in pages are always `noindex`. |
| `SEO_SITEMAP_CACHE_TTL` | `3600` | Seconds `/sitemap.xml` is kept before it is built again. |

## Server-side rendering

Pages are rendered in the browser. Rendering them on the server as well gives crawlers fully written pages: `npm run build:ssr`, then `php artisan inertia:start-ssr` under a process manager, and `INERTIA_SSR_ENABLED=true`. If the server is down, pages fall back to the browser ([Search engines and sharing](seo.md#rendering-on-the-server), [Deploying](deployment.md#server-side-rendering-optional)).

| Key | Default | What it does |
| --- | --- | --- |
| `INERTIA_SSR_ENABLED` | `false` | Ask the rendering server for each page's HTML. |
| `INERTIA_SSR_URL` | `http://127.0.0.1:13714` | Where the rendering server listens. |
| `INERTIA_SSR_RUNTIME` | `node` | The program that runs it: `node` or `bun`. |
| `INERTIA_SSR_ENSURE_RUNTIME_EXISTS` | `false` | Check the program is installed before starting. |
| `INERTIA_SSR_ENSURE_BUNDLE_EXISTS` | `true` | Check that `npm run build:ssr` has been run before asking. |
| `INERTIA_SSR_HOT_URL` | — | The rendering address while the Vite dev server runs. |
| `INERTIA_SSR_TIMEOUT` | — | Seconds to wait for it. |
| `INERTIA_SSR_THROW_ON_ERROR` | `false` | Raise an error when rendering fails, in place of falling back to the browser. For browser tests. |
| `INERTIA_ENCRYPT_HISTORY` | `false` | Encrypt the page data the browser keeps in its history for **every** page. A page that shows a secret (a new API key, the secret and the recovery codes of a two-factor setup) is encrypted whatever this says. The key is thrown away when the member signs out, so Back cannot bring a page back (needs HTTPS or `localhost`; [Seeing the recovery codes](two-factor.md#seeing-the-recovery-codes)). |
| `INERTIA_PRESERVE_BIG_INTEGERS` | `false` | Hand numbers beyond JavaScript's safe range to the page as `BigInt` instead of rounding them. |
| `INERTIA_DEVTOOLS_ENABLED` | — | Record each request for the Inertia DevTools extension. Empty records on a `local` site only. |
| `INERTIA_DEVTOOLS_GATE` | — | The gate that decides who may read the record. |
| `INERTIA_DEVTOOLS_TTL_HOURS` | `24` | Hours a record is kept. |
| `INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS` | `300` | How often old records are removed. |
| `INERTIA_DEVTOOLS_LIMIT` | `100` | The most records kept. |

## From Laravel and the tools

These are read by Laravel itself or by the tools around the site, not by a file of `config/`.

| Key | Default | What it does |
| --- | --- | --- |
| `BCRYPT_ROUNDS` | `12` | How much work hashing a password takes. Each step doubles it. |
| `PHP_CLI_SERVER_WORKERS` | one process | How many PHP processes `php artisan serve` starts (and so `composer dev`); `.env.example` says `4`. It has no effect under PHP-FPM, Herd or Valet. |

## Settings that are not in the environment

`config/` also holds what is not a setting of the environment, because it is a list or a decision that belongs with the code. Edit the file for these:

- the sources the Content Security Policy allows, in `config/security.php` ([security-headers.md](security-headers.md#adding-a-third-party-host));
- the subscription plans and their entitlements, the meters, in `config/billing.php` (plans are best managed under **Commerce** in the admin area, [billing.md](billing.md));
- the pages a "view as customer" session may open, in `config/impersonation.php`;
- the steps of the "Get started" checklist, in `config/onboarding.php`;
- what the audit log records and how it names actions, in `config/audit.php`.

Switching a section of the website on or off, the sign-in providers, the email-verification rule and the payment provider are settings kept in the database, under **System settings** in the admin area (see [Website sections and feature toggles](features.md#website-sections-and-feature-toggles)).
