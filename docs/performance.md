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

**A request must not do work for everyone who follows what it makes.** A comment on a blog post, and a reply in the forum, told every subscriber and every follower of the author *inside the request*. Measured with 2,000 subscribers (the same in-memory database, the queue faked, one comment):

| | Before | After |
|---|--------|-------|
| The request | 1,602 ms, 4,002 queries, 4,000 queued sends, +26 MB | 70 ms, 11 queries, 21 queued jobs, +2 MB |

The work did not go away: it moved to the queue, a hundred members to a job, where several workers can share it (`App\Jobs\NotifyMembers`, [notifications.md](notifications.md#telling-many-people)). The request only picks *who*. A site that runs its queue as `sync` still does it all in the request, and a site with no worker sees no notices for followers until one runs.

**A list made of two tables is cut by the database, not in PHP.** Admin → Forums → Reports shows the reports of threads and the reports of posts as one list, newest first. It read every report that matched, each with its thread, board, post, author and the two people, built a row for every one, sorted them in PHP and kept a page. Measured with 5,000 reports of threads and 5,000 of posts (the same in-memory database, pages of 25, one request each after a warm-up):

| Request | Before | After |
|---------|--------|-------|
| Every report (*All*) | 2,909 ms, 21 queries, +40.8 MB | 31 ms, 23 queries, +0.6 MB |
| The waiting ones (the default) | 1,011 ms, +16.1 MB | 28 ms, +0.5 MB |
| The third page of reports of posts | 1,548 ms, 17 queries, +30.0 MB | 32 ms, 19 queries, +0.6 MB |
| A search for part of a title | 956 ms, +13.2 MB | 54 ms, +0.6 MB |

`ForumReportController::reportKeys()` puts the two tables side by side as the least that can be sorted (which kind, its id, when it was made), the database counts that and cuts the page out of it, and only then are the page's reports read in full, by id. A kind that is not on the page is not read at all. Two queries more than before (the count and the page), and the same however many reports there are (`QueryBudgetTest`). `ForumReportListTest` holds the order, the filters, the pages and what is read. The same 3,000 reports and seven different filters, every page of each, came out identical before and after, except for reports made in the same second as another of the same kind, whose order was whatever the database happened to return: it is now the later report first (a thread's before a post's), so a report does not move from one page to the next.

**A public list is a page.** `GET /api/v1/polls` needs no key and returned every published and closed poll, with its options and their vote counts, in one answer. With 3,000 polls of four options and 30,000 votes it was 331 ms, 27 MB of PHP memory and **1.6 MB** of JSON for each request, from anybody; a page of 15 is 4 to 7 ms, 0.2 MB and 11 kB, and `?per_page=50` is 11 ms and 32 kB. The polls are cut by the database (`PollController::index`, 15 to a page, `?per_page=` up to 50), the votes of the key's owner are read for the polls on the page only, and the order has the id as its last term so that polls made in the same second do not move between pages. The list keeps its `polls` key and gains `links` and `meta`, like the other lists ([api.md](api.md)); a script that read every poll with one call now reads the first 15 and follows `links.next`, which is the one change to know about. `PollListApiTest` holds it.

This was the last of the two lists the sweep (every `->get()` in the controllers) had set aside; neither is left.

**A file is written as it is read.** The two CSV files of Admin → Search analytics (every logged search, and every term with its counts) were built from every row in memory before the first line was sent, and Laravel's `->get()` makes a model of each: 158 MB at 100,000 searches (past PHP's default limit of 128 MB, the download ended in a fatal error), 39 MB at 20,000 terms. Measured in an in-memory SQLite database, one request each:

| File | Before | After |
|------|--------|-------|
| Every search, 100,000 | 3.4 s, +158 MB | 2.2 s, +4.2 MB |
| Every search, 1,000,000 | out of memory | 21 s, +4.2 MB (one query for each thousand rows) |
| Every term, 20,000 | 0.96 s, +39 MB | 0.9 s, +5.5 MB |
| Every term, 200,000 | out of memory | 8 s, +14.5 MB |

The log is read from the newest row back, a thousand at a time, each page starting after the last row of the one before by `created_at` and id (`SearchAnalyticsController::searchesNewestFirst()`). The `created_at <= ?` line that comes before the `OR` is not decoration: with the `OR` alone SQLite read the index from its start again for every page, which took 104 seconds for the million, and with it the scan starts where the last page ended (the same query shape is what MySQL and PostgreSQL are best at, but this was not measured on them). The terms are kept as a list of ids in the order of the file and read by those ids, because the nightly aggregation rewrites their counts and a page that began at a count could miss or repeat a term. The 100,000 searches came out byte for byte the same before and after, and so did the 20,000 terms except that the 820 that tie (the same count and the same time) now come out later one first. The same file was also a hole: a visitor's search term was written as it was typed, so a search for `=HYPERLINK(...)` was a formula in the file staff opened ([search-index.md](search-index.md#the-search-log-and-its-two-files)). The exports of other people's words go through `CsvCell::text()`.

**The nightly aggregation is a few statements, not two for each term.** `AggregateSearchQueryStats` runs at 00:30 on the queue and adds the logged searches of the last `SEARCH_QUERY_AGGREGATION_WINDOW_DAYS` days up into one row for each term, which is what Admin → Search analytics and the terms file read. A worker stops a job after 60 seconds ([redis.md](redis.md#the-queue)), and this one read every term into memory as a model, wrote each with its own `updateOrCreate` (a select and a write) and then removed the rows of terms nobody searched for with `whereNotIn` and every term as a bind. Measured on MariaDB, in a database made for the run, with a skewed mix of terms and a tenth of the searches older than the window:

| Terms (searches logged) | Before | After |
|-------------------------|--------|-------|
| 18,615 (100,000) | 29.6 s, 37,234 queries, +33.1 MB | 0.4 s, 23 queries, +3.7 MB |
| 63,239 (300,000) | 99.4 s, 126,482 queries, +107.5 MB | 1.5 s, 68 queries, +6.2 MB |

On SQLite the 100,000 searches took 6.2 s, 37,234 queries and +31.4 MB before and 0.2 s, 23 queries and +2.2 MB after. At about 1.6 ms a term the old job passed the 60 seconds at some 37,000 terms (an estimate from the two runs above, not a run that big): the worker stopped it before the last step, so the rows of terms nobody searched for any more were never removed, and the next night did the same. PostgreSQL also refuses a statement with more than 65,535 binds, which is what the `whereNotIn` became at that many terms (PostgreSQL and MySQL 8 were not measured here; CI runs `AggregateSearchQueryStatsTest` on both, with 7,500 terms, which checks the number of statements and the binds in each and not the limit itself).

Now the database does the adding up: one `GROUP BY term` statement, read as a stream and not as models; each thousand terms are written with one upsert (`ON DUPLICATE KEY UPDATE` or `ON CONFLICT`, seven values a row, so 7,000 binds a statement), where a term that is there is brought up to date and keeps its `created_at`; and one `DELETE ... WHERE NOT EXISTS` (a search of that term in the window) removes the rest. It asks about each row, so the number of terms is not in the statement. Memory still grows by about 65 bytes a term (21.7 MB at 330,000 terms), because the MariaDB driver hands over the whole answer at once. An `INSERT ... SELECT` would be one statement, but InnoDB locks the rows it reads, which could hold up the searches being logged while it runs, so the terms go through PHP. The table it leaves is the same as before: the checksum of its rows was identical before and after at 18,615 terms (SQLite and MariaDB) and at 63,239 (MariaDB). `AggregateSearchQueryStatsTest` holds the counts, the edge of the window to the second, the removal and a second run that changes nothing.

**A member's data export is written as it is read, and cannot be stranded.** `GenerateUserDataExport` makes the zip behind Settings → Privacy → *Request an export*. It read everything the member has written into models, copied that into arrays, encoded the lot as one string for `export.json` and gave the zip a copy of it, so the memory it needed was many times the text (20,000 posts of about 0.9 kB are 16 MB of text, and the job took 130 MB more than it started with, with the 5,000 comments). A worker is given 256 MB (`docker/php.ini`), and PHP's out-of-memory error is not an exception, so the `catch` in the job never ran: the export stayed `processing` for good, and the page tells a member with an export in progress to wait for it and will not take another request. Measured on MariaDB, in a database made for the run, one member with that many posts and comments and also 300 tickets, 200 wishlist items, 300 reviews, 40 API keys and 100,000 usage events, under `memory_limit=256M`:

| Posts + comments | Before | After |
|------------------|--------|-------|
| 5,000 + 1,000 | 1.1 s, 82 MB peak (+32 MB) | 1.0 s, 56 MB (+6 MB) |
| 20,000 + 5,000 | 1.9 s, 180 MB (+130 MB) | 2.5 s, 56 MB (+6 MB) |
| 50,000 + 10,000 | **out of memory** | 5.6 s, 56 MB (+4 MB) |
| 100,000 + 20,000 | not run (past the limit at half of it) | 12.3 s, 60 MB (+8 MB) |

Memory no longer depends on how much the member wrote. Each list is read a page of 200 rows at a time and written to the file as it goes (`StreamedObject` writes `export.json` list by list, in the bytes `json_encode` gives for the whole document, and each section writes its row of `export.csv` as it yields its item), and the zip takes the two files as they are (`addFile`, from `tmpfile()`s that PHP removes when it is done with them, a fatal error too). The ids of a list are read first, in the order of the file (by time, then by id), a row at a time, and the rows are then read by those ids: a page that began where the last one ended would skip or repeat a row if one were deleted while the export ran, and a list of ids does not. The price is time, about 30% more at 20,000 posts (2.5 s against 1.9 s) because there are 163 queries and not 31, and some 12 s at 120,000 rows. At that rate the worker's 60 seconds would be reached at about 600,000 rows, which is an estimate and not a run, and an account that size would set `$timeout` on the job and raise `retry_after` above it ([redis.md](redis.md#the-queue)).

The same files, byte for byte: three members' exports (one with every kind of row, dates out of the order of the ids, two rows of the same time, a post without a date, deleted posts, products that are gone, unicode, quotes and backslashes, and formulas; one with a little; one with nothing) came out identical before and after, in the JSON and in the CSV. Two things that were not the same are meant. A text with bytes that are not UTF-8 made `json_encode` return `false` for the whole document, which the job wrote as an empty `export.json`; the bad bytes are now replaced with U+FFFD. And a failed export tells the member one sentence (`GenerateUserDataExport::FAILURE_REASON`) and not the exception's message, which for a database error has the query and its values in it (the page showed `failure_reason` as it was); the exception goes to the log and the failed jobs table as before. A job the worker gives up on without an exception in `handle()` (a timeout, or a worker killed and the job coming round again with no tries left) now reaches `failed()`, which marks a `pending` or `processing` export failed so that the member can ask again, and leaves a finished one alone.

**The morning email digest is many short jobs, and one member cannot hold up the rest.** `SendEmailDigests` runs at 08:00 and used to email every member with something waiting inside that one queued job: for each, claim their lines (a transaction with a lock), read the member and their notification settings, work out which lines they still want by email, delete the lines and queue one email (`EmailDigest::deliver()`, five statements a member), in order of the member's id. A worker stops a job after 60 seconds ([redis.md](redis.md#the-queue)). Measured on MariaDB, in a database made for the run, with every member holding lines:

| Members (lines each) | Before: the one job | After: jobs of 100 members |
|----------------------|---------------------|----------------------------|
| 1,000 (10) | 3.7 s | 10 jobs, the slowest 0.48 s |
| 5,000 (100) | 45 s | 50 jobs, the slowest 1.0 s |
| 10,000 (10) | 36.3 s | 100 jobs, the slowest 0.45 s |

At 3.7 ms a member with ten lines and 9 ms with a hundred, the 60 seconds are reached at about 16,000 and 6,600 members (an estimate from these runs, not a run that big). The job is then stopped, with the members it had reached finished and the others not, and since it goes by id the members it did not reach are the highest ones, again every morning. Three mornings with 10,000 members of ten lines each, a job allowed 20 seconds, and new lines for every member who had been served: about 5,500 members were served each morning, always the same ones, and **4,482 members got no email in the three**. A member whose delivery threw (a bad row, a mail error raised while the email was queued) stopped the job at their id in the same way, and every member after them went without, every morning, until someone looked.

Now `SendEmailDigests` only reads who is waiting, a hundred ids at a time and lowest first (`EmailDigest::waitingMemberIds()`: `WHERE user_id > ? ORDER BY user_id LIMIT 100`, so it never holds more than a page of ids and does not care that the jobs it queued are deleting lines), and queues a `DeliverEmailDigests` job for each page. The same three mornings: every one of the 10,000 members was served every morning, none was missed, the slowest job took 0.4 to 0.6 s, and queueing the hundred jobs took 0.2 s. The work is the same (3.7 ms a member, 37 s of worker time for 10,000 members): it is shared out, so that several workers can take it, and no job runs long enough to be cut off. A job reports a member whose delivery fails and goes on to the next, and that member keeps their lines for the next morning ([email-digests.md](email-digests.md#how-the-morning-job-runs)). Running a job again, as the worker's retries do, still sends a line once: the lines are claimed in the transaction that queues the email. Not done: the user and their notification settings are still read for each member, where a chunk could read them together (two statements a member fewer, an estimate, not measured). A site on the `sync` queue runs it all inside the scheduler at 08:00, as before.
**The sitemap is built from the rows it needs, once, and for the room that is left.** `/sitemap.xml` is kept for an hour (`SEO_SITEMAP_CACHE_TTL`), but the build on a miss runs inside a request, under PHP's memory limit (128 MB by default, 256 MB in `docker/php.ini`), and it is the same build for a big site as for a small one. It read the most `max_urls` (45,000) rows of *each* kind (blog posts, threads, products, members) as models, made an entry for every one of them and cut the list to `max_urls` at the end: a site past the cap read three times what it published. Measured on MariaDB, in a database made for the run, one cache miss each (the file is 45,000 entries, 6.6 to 7.2 MB, in all three):

| Threads / posts / members / products | Before | After |
|--------------------------------------|--------|-------|
| 20,000 / 5,000 / 20,000 / 500 | 2.6 s, +62 MB | 1.4 s, +17 MB |
| 50,000 / 20,000 / 20,000 / 5,000 | 5.3 s, +121 MB | 1.5 s, +17 MB |
| 200,000 / 100,000 / 100,000 / 20,000 | 8.0 s, +153 MB | 1.3 s, +17 MB |

(+ is the growth of PHP's memory at its peak during the build, measured with `memory_reset_peak_usage()`. The 17 MB does not depend on the size of the site: it is the text, about 7 MB, and copies of it. On SQLite, at three other sizes that also cross the cap (30,000 threads and 20,000 posts; 50,000 members; 60,000 threads), a miss took 2.5 to 3.6 s and +82 to +128 MB before, and 1.3 to 1.8 s and +13 to +17 MB after.) A build that grows by 121 MB or more does not fit what a 128 MB limit leaves once the framework has started, and PHP's out-of-memory error is fatal: the sitemap would not be built, nothing would be kept, and the next request would try again. (That is arithmetic from the figures, not a run of a request under that limit.)

Now there is one budget for the whole file. The fixed pages come first, and then each kind is read for the room that is left (`LIMIT` is the room, not the cap), a kind that comes after the file is full is not read at all, and a row is read as a plain row, a row at a time, with only the columns the entry needs (`->toBase()->cursor()`). Reading models one at a time with `cursor()` was tried first and was *slower* than reading them all at once (on SQLite, 1.5 s against 0.6 s for 45,000 threads, because `cursor()` builds a collection for every row), so the rows are not models at all: the entry needs a slug, a key and a time. The text is the same, byte for byte: the old and the new build were run on the same data at the three sizes above on MariaDB and at four shapes on SQLite (below the cap; past it with the posts and part of the threads in the file; past it with the members cut; past it with the threads alone), every run with a slug that has `&`, quotes, spaces and non-ASCII letters, a draft, a hidden thread, an inactive product, a banned member, a thread with no last post and a member with no date, and the files were identical.

Not changed, on purpose. **What is cut when the file is full** is still the end of the list, in the order the file has always had: the fixed pages, the blog's posts (newest first), the forum's boards and threads (latest post first), the products, and the members last. A site whose posts and threads alone fill 45,000 entries has no products and no members in the sitemap (200,000 threads and 100,000 posts: 44,996 posts and nothing else); a sitemap index with one file for each kind is the usual answer past that, and is not built. **A rebuild is still done by whoever asks first** after the hour, and several at once each build it: at 1.4 s it is cheap enough to leave, and the hour's wait is not shortened by it.
**Unpaid orders are expired by many short jobs, and an order the provider cannot answer about waits its turn.** `ExpirePendingOrders` runs every five minutes and used to do the whole job itself: for every pending order whose payment window had run out, from the lowest id, ask the payment provider what became of the payment (`PendingOrderExpirer`: one Stripe call per order, in turn), then cancel the order and give its stock back. A worker stops a job after 60 seconds ([redis.md](redis.md#the-queue)), and the next run starts again from the first order. An order the provider could not be asked about (the keys were changed, the provider was switched off, Stripe was down) stays as it is, because it may have been paid; so it stays at the front, is asked about and reported again at every run, and when there are enough of them the job is stopped before it reaches anything behind them. Measured with the real code, the schedule and the queue, on an in-memory SQLite database, with Stripe played by a stand-in that answers in 300 ms (an assumption: this was not measured against Stripe) and a worker that stops a job at 60 seconds, one worker, a run every five minutes:

| Orders due | Cannot be asked about, lowest ids | Before | After |
|------------|-----------------------------------|--------|-------|
| 1,000 | none | 200 a run, all cancelled at the 5th run (25 minutes) | all 1,000 in the 1st run (40 jobs, 300 s of the worker) |
| 1,000 | 150 | 50 a run (250 after 5 runs; 20 runs for all) | 850 in the 1st run, all in the 2nd |
| 1,000 | 250 | none, run after run (200 calls, all about the same 200 orders) | 750 in the 1st run, all in the 2nd |
| 1,000 | 2,000 | none in 8 runs (40 minutes) | none in the first two runs, all in the 3rd (15 minutes) |

The first row is the same work: before, each run was stopped at 200 calls; now the schedule queues 40 jobs of 25 orders (about 8 seconds each), and none is stopped, so the worker can use all five minutes. (20 runs for 150 is arithmetic from the 50 a run that five runs showed.) The orders that cannot be asked about are asked about once every 30 minutes (`RETRY_AFTER_MINUTES`) instead of at every run, and each failure is reported once in that time; in the last row they are asked about again at the 7th run, 30 minutes after the 1st. A run of the schedule that finds the queue behind adds the same orders again as new jobs; those are cheap (a job reads its orders again and finds them cancelled: 20 ms in the run above, no provider call).

Not changed, on purpose: **an order the provider cannot answer about is still never cancelled**, however long that is, because cancelling an order that was paid is worse than holding its stock; it only waits 30 minutes between tries. Not done: the Stripe client has no timeout of its own, so it has the library's default of 80 seconds, longer than the worker's 60. A call that hangs stops its job (the 25 orders in it are tried again, by the worker's retries and then by the next run), and no other job is held up by it; a timeout of about 15 seconds on these calls would be the next step. A site on the `sync` queue runs it all inside the scheduler, as before.

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
