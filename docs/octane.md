# Running on Octane (and other long-lived workers)

What the app keeps in memory from one request to the next, which of it is a problem for a process that serves many requests, what was changed, and how to try Octane. PHP-FPM, which builds the app afresh for each request, stays the default.

> **Status:** audited, not run. Octane is not a dependency of this project and nothing here has been served by it. The audit is from reading the app, and Octane's own source and documentation (2.x), and the findings are held in place by tests that run everywhere (`tests/Feature/Performance/OctaneSafetyTest.php`). **Not built:** an Octane CI job, Horizon, and anything that needs a server binary; see [below](#not-built-and-not-verified).

## Why this is not only about Octane

Three things keep a PHP process alive between requests: Octane, `php artisan queue:work` and `php artisan schedule:work`. In each, a static property, a singleton and the one database connection outlive the request or job that created them. The queue worker is already in every production setup, so a finding below that is about "a worker" is true of the site as it is run today.

| | Rebuilt for each | Keeps |
|---|---|---|
| PHP-FPM | request | nothing |
| `queue:work` | nothing; `scoped` bindings are forgotten after each job | singletons, statics, the database connection |
| Octane | nothing; the app is copied for each request and `scoped` bindings are forgotten after it | what the app had built when it booted: singletons it resolved then, statics, the connection |

## How Octane serves a request

This is what the audit assumed, from Octane's source:

- The app is built and booted once for each worker. Every provider's `register()` and `boot()` run then, and never again. A short list of framework services (the session, the cache, the router, the translator...) is resolved up front.
- A request is served by a **copy** of that app (`clone`). The container helper (`app()`) and the facades are pointed at the copy for the length of the request. What the copy builds is thrown away with it. What the app had already built is shared by every request.
- `scoped` bindings are forgotten after each request. The session, the signed-in user, the locale (including Carbon's), the Vite state, Inertia, Scout and the copy of the configuration are reset by Octane itself.
- A worker is restarted after 500 requests by default (`--max-requests`), which hides slow leaks and not fast ones.

So what is not reset is what the app itself keeps: its static properties, its singletons that were built while booting, and any closure or object that holds on to `$this->app` (the app the providers were booted in), a request or a user.

## What was found

| Where | Finding | Done |
|---|---|---|
| `AppServiceProvider`, the listener that forgets the settings read so far when a transaction is rolled back | It called `$this->app->make(...)`. `$this->app` is the app the provider booted in, not the copy serving the request, so under Octane a rollback forgot a snapshot nobody was using and the request kept reading what the rolled back transaction had written. `SystemSetting` itself uses `app()`, which is the copy. | The listener calls `app()`, like everything else that reads the settings. |
| `AppServiceProvider::boot()`, `SET time_zone = '+00:00'` for MySQL | A statement run once for the process. A worker that loses its connection (MySQL closes an idle one after `wait_timeout`) and makes another would then store and read timestamps in the server's own time zone. A queue worker could already do this. | `'timezone' => '+00:00'` on the `mysql` and `mariadb` connections in `config/database.php`, which Laravel runs every time it connects. (`mariadb` had nothing before, so a site on that driver was in the server's time zone; it is now in UTC like `mysql`.) |
| `spatie/laravel-permission`, `register_octane_reset_listener` | The package reads which role holds which permission once and keeps it in memory. The admin area changes it, but only the worker that handled the change forgets it, so another worker kept serving the old list (a role that lost a permission still had it) until it restarted. | The setting is `true` in `config/permission.php`. The package registers the listener only when Octane is installed. |
| `OAuthManager` | Bound as a singleton, holding the container, the session and the providers it built from them. | `scoped` |
| `PaymentManager` | Bound as a singleton, holding the container. | `scoped` |
| `TebexStoreProfile` | Bound as a singleton, holding the store profile it read from the settings. A long-lived process kept the first profile it read, so a store switched to another platform (or disabled) through "Test connection" in a web request was not seen by it. | `scoped`, so each request and each job reads it again. |

Looked at and left as they are:

| Where | Why it is safe |
|---|---|
| `SubscriptionManager`, `HtmlSanitizer` (singletons) | Neither holds a request, a user, the container or a setting. The sanitiser builds its rules from constants. |
| `SettingsSnapshot`, `Seo`, `Audit` | Already `scoped`. `AuditObserver` asks for `Audit` with `app()` when a model changes, so it is the request's own. |
| `StripeWebhookController::$applying` | Set when a delivery starts and removed in a `finally`: empty between requests. |
| `PreferenceOptions::$timezoneOptions`, `$localeOptions` | PHP's list of time zones and ICU's list of locales (each named in its own language, not the request's): the same for every request. |
| `Ownership::$owners`, `$newRecordOwner` | Set once when teams plug in (1.1), and given the user each time. The tests flush them. |
| `SetLocale` | Sets the app's locale and Carbon's for each web request, and Octane resets both for each request, so an API request does not inherit the last page's. |
| The CSP nonce | `SecurityHeaders` asks `Vite` for a new one on every web request, and Octane calls `Vite::flush()` for each. |
| Impersonation | The user is swapped for the request and put back before the session is saved; Octane also resets the authentication state between requests. |
| `config()` | Nothing writes to the configuration while serving a request. |
| `Vite::prefetch(...)`, `URL::forceHttps()`, rate limiters, gates, observers | Set up once at boot, and they hold no request: closures take the request or user they are given. |

## Rules for new code

`OctaneSafetyTest` fails when a new static property or a new `singleton`/`instance` binding is added without being listed with its reason. The rest is for whoever writes the code:

- **Bind with `scoped` unless the class is plain.** A class that holds the container, the request, the session, a user, a value read from the database or the settings, or a cache of any of them, is rebuilt for each request and job with `$this->app->scoped(...)`. It costs nothing under PHP-FPM.
- **Resolve when the code runs, not when it is registered.** In a closure that a provider registers (an event listener, a gate, a macro), call `app(...)`, `request()` or `auth()->user()` inside it. Do not use `$this->app`, and do not keep the request or user in a variable the closure captures.
- **No static property that grows or holds a request.** A static array that a request adds to is a leak that grows until the worker restarts.
- **Session state of a connection belongs in its configuration.** A statement run in `boot()` is run once.
- **Do not write to `config()` for one request.** In Octane the copy of the configuration is reset, but a queue worker's is not.

## Trying it

None of this is needed to run the site; PHP-FPM is the supported default. If you want Octane:

```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp   # or swoole, or roadrunner
php artisan octane:start --workers=4 --max-requests=250
```

This adds a package and, for FrankenPHP and RoadRunner, downloads a server binary: neither is part of this repository. Run it behind nginx or Caddy as [Laravel's guide](https://laravel.com/docs/octane#serving-your-application-via-nginx) describes, set `OCTANE_HTTPS=true` behind HTTPS, and run `php artisan octane:reload` after a deploy, as you run `php artisan queue:restart` for the queue workers.

Octane's workers are separate processes, so the cache and the sessions must not be per-process: the `array` driver is not usable, and the database (the default) and Redis ([redis.md](redis.md)) both are.

Before relying on it, with two workers (`--workers=2`) and a few thousand requests:

1. Sign in as two people in two browsers and make sure neither is ever shown the other's account, cart or notifications.
2. Change a role's permissions in the admin area, then use the site as someone with that role: the change should show on the very next request, whichever worker serves it.
3. Switch maintenance mode, or a website section, on and off, and watch it follow on the next request.
4. Check that each page response has its own CSP nonce.
5. Receive a Stripe test webhook, upload a file and place a test order.
6. Watch the workers' memory over a few thousand requests: it should level off.

## Not built, and not verified

- **Not run under Octane.** The findings come from reading Octane's code and documentation, and from a test that serves a request from a copy of the app the way Octane does ([what the test does](#testing)). The behaviour of each server (FrankenPHP, Swoole, RoadRunner) is untried here.
- **No Octane CI job.** It would need the package and a server in the workflow; nothing here yet decides to support Octane in 1.0, and it is not part of the exit criteria.
- **Horizon** (the queue dashboard) is a separate package and a separate decision ([redis.md](redis.md#what-it-does-not-do)).
- **The Octane cache driver and tables** (Swoole only) are not used.
- **Third-party packages** are as good as their own Octane support. The permission package's is covered above; the others were not read.

## Testing

`tests/Feature/Performance/OctaneSafetyTest.php`:

- every static property in `app/`, and every `singleton`/`instance` binding, is on a list with the reason it is safe, and the lists have no entry that is gone;
- a freshly booted app has not built any of the per-request services (so a provider cannot start building one at boot), and each of them is built again after the scope is reset, as `queue:work` and Octane do between jobs and requests;
- a rolled back transaction forgets the settings in the container serving the request: the test serves it from a `clone` of the app, with the container helper and facades pointed at the copy, as Octane does, and fails on the old listener;
- the MySQL and MariaDB connections carry `timezone`, and, on MySQL and MariaDB, a connection made from scratch reports `+00:00` without anything having been run on it;
- the permission package's Octane reset is on.
