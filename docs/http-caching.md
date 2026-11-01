# Documents and caching

The files the app serves that are the same for everyone (`robots.txt`, the sitemap, the feeds) start no session, set no cookie, and tell any cache between the server and the reader that it may keep them.

> **Status:** in place for eight documents. Not built: caching of pages (the HTML), the public API, and anything that needs a design decision first. They are listed at the end.

## Why

A request for `robots.txt` went through the same stack as a page. For a guest that meant, with the default `database` session driver:

- a session read, then a session **write**: a new row in `sessions` (with the address and user agent) for every visit, because a crawler or a feed reader keeps no cookie and so is a new guest every time;
- two `Set-Cookie` headers (the session and `XSRF-TOKEN`), about 1 KB, on a file that is 26 bytes;
- `Cache-Control: no-cache, private` and no `ETag`, so a cache in front of the app could keep nothing, and a feed reader that asks every fifteen minutes downloaded the whole feed every time.

Measured on a guest with an empty database and database sessions, one request each:

| Document | Session queries | Cookies | Headers |
|----------|-----------------|---------|---------|
| `/sitemap.xml` | 3 → 0 | 2 → 0 | 1,523 → 552 bytes |
| `/robots.txt` | 3 → 0 | 2 → 0 | 1,481 → 510 bytes |
| `/.well-known/security.txt` | 3 → 0 | 2 → 0 | 1,481 → 510 bytes |
| `/site.webmanifest` | 3 → 0 | 2 → 0 | 1,481 → 510 bytes |
| `/blogs/feed` | 3 → 0 | 2 → 0 | 1,491 → 519 bytes |
| `/forum/feed` | 3 → 0 | 2 → 0 | 1,491 → 519 bytes |
| `/api/docs/openapi.json` | 3 → 0 | 2 → 0 | 1,515 → 542 bytes |

The three session queries are a read, an insert and a read; the feeds' own queries (two for the blog feed, one for the forum feed) are unchanged. A second request with `If-None-Match` is a `304` with no body.

Nothing was measured on a busy site: a session row for each visit is what grows with the number of crawlers and readers, and how fast depends on how many there are. The sessions table is swept by the lottery at the end of some requests (`session.lottery`), and rows live for `session.lifetime` minutes.

## What changed

`App\Support\Routing\PublicDocuments::cachedFor($seconds)` declares a route as a document:

```php
PublicDocuments::cachedFor(300)->get('/blogs/feed', [BlogController::class, 'feed'])->name('blogs.feed');
```

| Document | Kept for |
|----------|----------|
| `sitemap.xml` (the app also builds it once an hour, `seo.sitemap.cache_ttl`), `robots.txt`, `security.txt`, `site.webmanifest`, `/api/docs/openapi.json` | 1 hour |
| `/blogs/feed`, `/forum/feed`, `/forum/{board}/feed` | 5 minutes |

- **Cache headers.** Laravel's `cache.headers` middleware sets `Cache-Control: public, max-age=…` and an `ETag`, and answers a matching `If-None-Match` with `304`. It does nothing for an answer that is not a success: a section switched off (`404`) or maintenance (`503`) is never marked for keeping.
- **No session.** `PublicDocuments::WITHOUT` lists what the web group runs that needs a session or a signed-in member, or that makes the answer depend on the reader: starting the session, flashed errors, the forgery token, the banned-member check, the theme, **the language**, the Inertia share, the last-activity stamp, the audit log and the read-only view as a customer. What stays is the cookie encryption, `SecurityHeaders`, the maintenance check and route binding. `PublicDocumentsTest` fails when the web group gains a middleware that is in neither list.
- **The same for everyone.** A document does not change with the language, the theme or a cookie: the test asks every one with other values for all three and compares the body and the `ETag`.
- **Maintenance.** `EnsureSiteIsAvailable` lets staff through, which means asking who is signed in. A request with no session has nobody to ask, and asking the guard anyway could sign a member in from a remember-me cookie with nothing to keep it in (and fire the sign-in event). It now asks only when the request has a session. `security.txt` still stays up during maintenance.

## What it means for a site

- **A change can take up to the time above to show** in a shared cache (a CDN, a proxy) and in a reader that keeps it. Switching `SEO_INDEXING` on at launch, or changing the security contact, is not seen by a crawler at once: purge the CDN or wait an hour.
- **A CDN needs nothing set up.** The headers say `public`, nothing varies on a cookie or a language, and no document sets a cookie. Put a CDN in front and the documents are kept at the edge.
- **The blog feed is up to five minutes late.** Asking for it publishes the posts whose scheduled time has passed (`publishDueScheduledBlogs`), and it is now asked less often. The posts' own pages publish a due post on their own, as before.
- **A kept `200` outlives maintenance by its time.** A `503` is never kept, but a cache that kept the sitemap before maintenance began may serve it for up to an hour.

## Adding a document

Only a file that is the same for every reader, that reads no session and no signed-in member, and whose bytes change when its content does:

1. Declare the route with `PublicDocuments::cachedFor($seconds)`. A route that needs a binding (`{board:slug}`) works: route binding stays.
2. Add a line to `PublicDocumentsTest::documents()`. The test then checks that it starts no session and sets no cookie, that a cache may keep it for that long and a reader can ask whether it changed, and that it is the same whoever asks. A test also fails when a route runs without a session and is not in that list.

A controller that reads `$request->user()` or the session is not a document, whatever it returns.

## What is not covered

- **Pages (the HTML).** Every page carries a one-time `nonce` for the Content Security Policy (8 to 15 times, in the markup and in the header), a CSRF token in a `<meta>` tag, and the session cookie. An `ETag` and a `304` cannot work: a `304` would keep the old body with a header that holds a new nonce, and the browser would block the scripts. Keeping the page for guests would mean replacing the nonce with hashes and fetching the token and the flashed messages separately. That changes the security policy and the page shell, and is a decision for its own slice.
- **The public API.** `routes/api.php` has its own middleware (keys, scopes, throttles, the usage meter), and a response a cache keeps never reaches the meter. It needs its own review.
- **Files the web server serves** (the build in `public/build` has hashed names; covers and product pictures are in [images.md](images.md)). Cache them in the web server or the CDN.
- **Counts and fragments** (the members count, trending lists). Each is a query that wants measuring first.

## Checking a site

```bash
curl -sI https://your-site.example/robots.txt
```

expects `Cache-Control: max-age=3600, public`, an `ETag` and no `Set-Cookie`. To see the `304`:

```bash
curl -sI -H 'If-None-Match: "<the ETag from the first answer>"' https://your-site.example/robots.txt
```
