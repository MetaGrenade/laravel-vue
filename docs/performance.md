# Performance

How fast a guest's page starts to answer, how to measure that on your own site, and which settings the number depends on.

> **Status:** the time to first byte is measured on a reference setup and has a command, `npm run ttfb`, to measure any site. The roadmap target (under 200 ms) is met by a factor of two to four on a laptop, and only with OPcache on. The Largest Contentful Paint is reported by Lighthouse ([lighthouse.md](lighthouse.md)): 1.1 s on simulated slow 4G for the home page, a blog post and a product page, against a target of 2.5 s. Whole pages are not cached for guests in 1.0, and [why](#whole-pages-are-not-cached-for-guests) and what to turn on instead are written down. Not measured: a small VPS, Redis, and a site under load.

## The targets

From section 5.2 of the roadmap:

| Target | State |
|--------|-------|
| A guest page starts answering in under 200 ms, on a small VPS with Redis and OPcache | Measured below: 50 to 90 ms on a laptop |
| Largest Contentful Paint under 2.5 s on 4G for the home page, a blog post and a product page | Reported by Lighthouse, not held to a budget: 1.1 s for all three on the seeded site ([lighthouse.md](lighthouse.md)). What a page downloads is held to a budget ([bundle-budget.md](bundle-budget.md)) |
| No N+1 queries on the top pages | Enforced in tests ([query-budgets.md](query-budgets.md)) |

## Measuring your own site

```bash
npm run ttfb -- https://example.com
```

It asks for ten pages a guest can open (the home page, the blog, the forum, the member list, the leaderboard, the shop, pricing, support, the login page and `robots.txt`), each 15 times after 3 requests to warm it. Each request is on a new connection, like a new visitor's, and the time counted is from the start of the request to the arrival of the answer's first byte, so DNS, TCP and TLS are inside it. Read the **median**: one slow request in a row is noise (two runs of the same setup here differed by up to 15 ms on a page). It exits 1 when a page's median is over the budget, or when the page answers anything but 200 **even once** in its runs (a page that fails 2 times in 15 is reported as that, and its time is taken from the answers that were served, since an error comes back faster than a page), so it can sit in a deploy script.

| Option | Meaning |
|--------|---------|
| `--runs=15` | Requests counted for each page |
| `--warmup=3` | Requests made first and not counted (a cold cache, the first compile of a view) |
| `--budget=200` | Milliseconds the median may be |
| `--path=/blogs/a-post` | A page to measure instead of the ten. Repeat it for more, e.g. a blog post or a product |

Run it from where your visitors are to see what they see, and from the server itself (`http://127.0.0.1`, with the site's `Host` set up as a local alias) to see what the application costs without the network. It also tells you when a text page arrives uncompressed (below). The command has tests of its own, and `npm run bundle:test` runs them.

## What it measured here

The reference setup is a developer laptop on Windows 10, PHP 8.4 with its built-in web server (one process, one request at a time), PostgreSQL 16 in Docker on the same machine, `APP_ENV=production`, `APP_DEBUG=false`, `php artisan optimize`, OPcache on, and sessions and cache in the database. The site held 3,000 members, 150 blog posts with 3,000 comments, 8 boards with 1,500 threads and 15,000 posts, and 120 products with 600 reviews. The requests are a guest's, with no cookie, so every one started a session.

The middle and the slowest tenth of 15 requests, in milliseconds (the average of two runs):

| Page | Median | p90 |
|------|--------|-----|
| `robots.txt` (the framework starting and nothing else) | 21 | 30 |
| Home | 52 | 64 |
| Blog list | 70 | 81 |
| A blog post | 70 | 89 |
| Forum index | 58 | 64 |
| A board | 70 | 79 |
| A thread (10 posts) | 89 | 103 |
| Member list | 57 | 71 |
| A member profile | 69 | 77 |
| Leaderboard | 57 | 68 |
| Shop | 68 | 83 |
| A product | 70 | 76 |
| Pricing | 54 | 64 |
| Support | 55 | 58 |
| Login | 50 | 58 |
| Search | 77 | 88 |

Where the time goes: about 20 ms is the framework starting for the request (`robots.txt`), and the rest is the page. With the application already started, a page takes 14 to 37 ms, of which the database is 3 to 17 ms for 3 to 19 queries (a thread is the most). A database on another machine adds its round trip to each of those queries, so 1 ms of network is at most 19 ms on the busiest page.

The slowest page was 103 ms at the p90, half of the 200 ms target.

## What each setting is worth

The same requests with one thing changed, the mean over the 15 pages above:

| Setting | Mean median | Change |
|---------|-------------|--------|
| Everything as above | 64 ms | |
| Sessions and cache in files, not the database | 58 ms | 7 ms less |
| No `php artisan optimize` (config, routes, events and views read and compiled on each request) | 71 ms | 6 ms more |
| **OPcache off** | **272 ms** | **207 ms more; every page between 246 and 322 ms** |

OPcache is the setting the target depends on: without it PHP reads and compiles every file a request uses (the framework alone is hundreds) on every request, and no page is under 200 ms. `php artisan optimize` and moving sessions off the database are worth a few milliseconds each, and sessions off the database also keep a row from being written for every visit ([redis.md](redis.md) says how). Redis was not measured here.

## Whole pages are not cached for guests

Decided for 1.0: a guest's page is built for each request and no copy of it is kept. To make a guest's page faster, turn on OPcache first ([the checklist](#a-production-checklist), item 3). Keeping pages is revisited in 1.1.

**Why a page cannot simply be kept.** Every page carries a nonce for its Content Security Policy that is new in each response (two requests for `/login` got two different ones), a CSRF token in a `<meta>` tag, and the session cookie. A guest's page is sent `Cache-Control: no-cache, private`, so a browser, a CDN or a proxy keeps none of it. A kept copy would either break or defeat the nonce. Served whole, every visitor would get the same nonce, and a nonce that is not new each time protects nothing; revalidated with a `304`, the old body would sit under a header with a new nonce, and the browser would block the scripts. The nonce is what stops a script that someone injected from running ([security-headers.md](security-headers.md)), and it stays strict. Making pages keepable means replacing it with hashes and fetching the token and the flashed messages separately, which changes the security policy and the page shell ([http-caching.md](http-caching.md#what-is-not-covered)).

**Why it is not worth that for 1.0.** The most a cache of the page could save is what the page costs: 50 to 90 ms on the reference setup, about 20 ms of it the framework starting. OPcache off costs 207 ms more on average, and it is a setting. In order, then: OPcache, `php artisan optimize`, compression of text, and sessions and cache on Redis (the checklist below). The documents that are the same for everyone are already kept ([http-caching.md](http-caching.md)), the built assets can be cached for a year (item 5 of the checklist), and a CDN can take the images (`PUBLIC_DISK_URL`, [images.md](images.md)).

**When to look at it again.** If measuring a real site, under load, shows that a guest waits for the page and not for the start-up, the next step is the one above (hashes and a separate token), as its own change with its own tests. Counts and trending lists can be kept for a few minutes without any of that, one at a time, once each is measured.

## What a page weighs

A guest's page is one HTML document that starts the front end. The login page is 71,215 bytes as the application writes it and **15,820 bytes gzipped**, because most of it is repeated text:

| Part | Written | Gzipped |
|------|---------|---------|
| The route table for the front end (207 routes, none of them in the staff area) | 45.7 kB, 24.5 kB of it route data | 3 kB (the route data) |
| The page's own data (its props, the interface text it needs: 7.9 kB of the 10.9 kB) | 10.9 kB | 3.3 kB |

The rest is the head of the page, its stylesheet and script tags. A signed-in user's page has one more part, **the script that fetches the other pages in the background once this one has loaded: 43 kB written and 3.0 kB gzipped for a member who is not staff, 71 kB and 4.5 kB for staff**, whose list includes the admin pages. Every visitor's page had it until it was limited to signed-in users: the same login page was 142,552 bytes and 20,550 gzipped (the home page 144,027 and 20,722), so the visitor's page is now half the size written and about a quarter smaller gzipped.

So the web server (or the CDN in front of it) must compress `text/html`. Uncompressed, a visitor's home page is 73 kB to send, 0.4 s on a slow 4G link (1.6 Mbit/s) before anything else (it was 144 kB, 0.7 s); the login page, which has the same parts, is 16 kB compressed. `npm run ttfb` says when it sees a page arrive uncompressed.

The background fetch is `PrefetchAssetsForMembers` ([bundle-budget.md](bundle-budget.md#what-it-does-not-do)): for a signed-in user it downloads the rest of the front end after the page has loaded: about 680 kB gzipped for a member who is not staff, and about 980 kB for staff, who are also sent the admin pages. A visitor does not download it. The page sizes above are from plain requests (no compression asked for) for `/`, `/login`, `/shop`, a blog post and a forum thread, as the application writes them, and gzipped at level 6; a visitor's pages came to 71 to 76 kB written and 15.8 to 17.1 kB gzipped.

## Pages that grow with the data

The [query budgets](#what-it-measured-here) count a page's queries, so they hold the same with ten rows or ten thousand, and a page that reads every row of a table passes them. Two admin pages did, found by reading every `->get()` in the controllers and measuring the ones that grow (an in-memory SQLite database with 20,000 members, 20,000 closed support tickets, and 4,000 recent open ones with 12,000 messages; one request each, so the order of size and not a server's figure):

| Page | Before | After |
|------|--------|-------|
| Admin → Dashboard | 1,127 ms, +78 MB of PHP memory | 214 ms, +12 MB |
| Admin → Tokens | 480 ms, +56 MB, **1.4 MB** of page (1.35 MB of it the list of every member, for the *User* box of the Create dialog) | the list is gone: the box searches as staff type, ten matches at a time |

- **The dashboard's support numbers are worked out by the database.** The average time to close a ticket read every closed ticket there had ever been into PHP, the average first response read every ticket of the last eight weeks *with every message in it, text included*, and the queue's age read every open ticket. Now each is one statement (`AdminController`: a grouped count, one join that finds each ticket's first answer, `Sql::secondsBetween()` for a time difference that differs by database). `AdminDashboardSlaMetricsTest` holds the numbers, with the edges of every bucket written out by hand, and fails if the dashboard reads the text of a message.
- **A picker for a member is a search.** `StaffUserSearch` finds ten members by part of a nickname or email, or one by id; the support pages and the Create dialog of Admin → Tokens use it (`acp.support.users.search`, `acp.tokens.users.search`). Never hand a page a list of all the members.

**Left as it is, and why:** the forum report list (Admin → Forums → Reports) reads every report of the chosen status and pages them in PHP, which is fine for the default (waiting) view and heavier for *All* and *Resolved* on a forum with many; it has not been measured. The API's poll list returns every published poll without paging. Both grow with what staff or moderators make or settle, not with the number of visitors, so they come after the pages above. A comment on a blog post notifies every subscriber inside the request; that is a different problem (the work, not the page) and is not touched here.

## A production checklist

1. **`composer install --no-dev --optimize-autoloader`.** The classmap is read once, and the test tools are not on the server.
2. **`php artisan optimize` on every deploy,** after the `.env` is final. It builds the config, route, event and view caches. With the config cached, the `.env` file is no longer read, so a change to it needs `php artisan optimize` again.
3. **OPcache on, and not checking for changes.** In `php.ini` (or the pool's `php_admin_value`):

   ```ini
   opcache.enable=1
   opcache.memory_consumption=256
   opcache.interned_strings_buffer=32
   opcache.max_accelerated_files=20000
   opcache.validate_timestamps=0
   ```

   The application and its packages are about ten thousand PHP files (a few fewer without the test tools), close to PHP's default limit of `10000`, so set a higher one. With `validate_timestamps=0` PHP never looks at a file again, so **reload PHP-FPM after each deploy** (for example `systemctl reload php8.4-fpm`), or the old code goes on running. The command line (`php artisan`, queue workers) has its own switch, `opcache.enable_cli`, which is off by default and does not matter here.
4. **Compress text responses** at the web server. In nginx:

   ```nginx
   gzip on;
   gzip_comp_level 5;
   gzip_vary on;
   gzip_types text/css text/plain text/xml application/json application/javascript application/xml image/svg+xml;
   ```

   `text/html` is compressed by default once `gzip on` is set. If a CDN is in front, let it compress and brotli if it can; the check is `npm run ttfb -- https://your.site`.
5. **Cache the built assets for a year.** Their names carry a hash, so a changed file has a new name:

   ```nginx
   location /build/assets/ {
       expires 1y;
       add_header Cache-Control "public, immutable";
       access_log off;
   }
   ```

6. **Sessions, cache and queue on Redis** ([redis.md](redis.md)), so that a guest's visit does not write to the database.
7. **HTTP/2 or HTTP/3 and TLS session resumption** on the web server, so that the connection setup inside a first byte is short.
8. **Check it after a deploy:** `npm run ttfb -- https://your.site`. It exits 1 when a page is over 200 ms.

The nginx settings above are the standard ones and were not run in this repository's tests.

## What it did not measure

- **Largest Contentful Paint.** It needs a browser and a throttled network, and `npm run lighthouse` supplies both ([lighthouse.md](lighthouse.md)), on a small seeded site and without a real web server in front. The front end is not rendered on the server, so a page paints after its script has loaded and run; the size of that script is what [bundle-budget.md](bundle-budget.md) holds down. No time fails a CI run: a Lighthouse number moves with the machine.
- **A small VPS, or PHP-FPM on Linux.** The figures come from PHP's built-in server on Windows, one request at a time. A VPS may be faster or slower, so treat them as the order of size, and run `npm run ttfb` on yours.
- **Load.** These are one request at a time. How many a second a server can take depends on the number of PHP-FPM workers, and a database query that takes 1 ms alone can take longer when many run at once. Octane ([octane.md](octane.md)) would remove the 20 ms start-up and has been audited but not run.
- **Signed-in pages.** They do more (the cart, the notification count, the permissions) and are not cached for anyone else. The query budgets hold their queries, not their time.
- **Redis, brotli, a CDN, a remote database.**
