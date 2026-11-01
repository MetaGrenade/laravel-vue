# Performance

How fast a guest's page starts to answer, how to measure that on your own site, and which settings the number depends on.

> **Status:** the time to first byte is measured on a reference setup and has a command, `npm run ttfb`, to measure any site. The roadmap target (under 200 ms) is met by a factor of two to four on a laptop, and only with OPcache on. Not measured: the Largest Contentful Paint (that needs a browser on a throttled network, which is the Lighthouse work in the roadmap), a small VPS, Redis, and a site under load.

## The targets

From section 5.2 of the roadmap:

| Target | State |
|--------|-------|
| A guest page starts answering in under 200 ms, on a small VPS with Redis and OPcache | Measured below: 50 to 90 ms on a laptop |
| Largest Contentful Paint under 2.5 s on 4G for the home page, a blog post and a product page | Not measured. What a page downloads is held to a budget ([bundle-budget.md](bundle-budget.md)) |
| No N+1 queries on the top pages | Enforced in tests ([query-budgets.md](query-budgets.md)) |

## Measuring your own site

```bash
npm run ttfb -- https://example.com
```

It asks for ten pages a guest can open (the home page, the blog, the forum, the member list, the leaderboard, the shop, pricing, support, the login page and `robots.txt`), each 15 times after 3 requests to warm it. Each request is on a new connection, like a new visitor's, and the time counted is from the start of the request to the arrival of the answer's first byte, so DNS, TCP and TLS are inside it. Read the **median**: one slow request in a row is noise (two runs of the same setup here differed by up to 15 ms on a page). It exits 1 when a page's median is over the budget, or the page answers anything but 200, so it can sit in a deploy script.

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

## What a page weighs

A guest's page is one HTML document that starts the front end. The login page is 142,002 bytes as the application writes it and **20,367 bytes gzipped**, because most of it is repeated text:

| Part | Written | Gzipped |
|------|---------|---------|
| The route table for the front end (207 routes, none of them in the staff area) | 45.7 kB, 24.5 kB of it route data | 3 kB (the route data) |
| The script that fetches the other pages in the background once this one has loaded | 70.9 kB | 4.5 kB |
| The page's own data (its props, the interface text it needs: 7.9 kB of the 10.9 kB) | 10.9 kB | 3.3 kB |

So the web server (or the CDN in front of it) must compress `text/html`. Uncompressed, a home page is 144 kB to send, 0.7 s on a slow 4G link (1.6 Mbit/s) before anything else; the login page, which has the same parts, is 20 kB compressed. `npm run ttfb` says when it sees a page arrive uncompressed.

The background fetch is the `Vite::prefetch` setting that is still an open decision ([bundle-budget.md](bundle-budget.md#the-editor-on-the-thread-page)): it costs the 71 kB script above in every page and then downloads the rest of the front end, about 990 kB gzipped, after the page has loaded.

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

- **Largest Contentful Paint.** It needs a browser and a throttled network. The front end is not rendered on the server, so a page paints after its script has loaded and run; the size of that script is what [bundle-budget.md](bundle-budget.md) holds down. A Lighthouse run in CI is a new package and waits for a decision.
- **A small VPS, or PHP-FPM on Linux.** The figures come from PHP's built-in server on Windows, one request at a time. A VPS may be faster or slower, so treat them as the order of size, and run `npm run ttfb` on yours.
- **Load.** These are one request at a time. How many a second a server can take depends on the number of PHP-FPM workers, and a database query that takes 1 ms alone can take longer when many run at once. Octane ([octane.md](octane.md)) would remove the 20 ms start-up and has been audited but not run.
- **Signed-in pages.** They do more (the cart, the notification count, the permissions) and are not cached for anyone else. The query budgets hold their queries, not their time.
- **Redis, brotli, a CDN, a remote database.**
