# Lighthouse

A report of how the pages a visitor opens first score in Lighthouse, kept with every CI run and easy to produce on your own machine. It reports; it does not hold a budget.

> **Status:** built: `npm run lighthouse`, the `lighthouse (report)` CI job, and a few checks that fail a run. **Not built:** a budget on any score or time (see [why](#why-no-score-fails-a-run)), and a run on a site that has real data and a real web server in front of it (see [what it does not show](#what-it-does-not-show)).

## What it does

```bash
npm run lighthouse
```

It starts the application the way the browser suites do (`tests/e2e/serve.mjs`, on a database of its own, `database/lighthouse.sqlite`, filled by `AccessibilityFlowSeeder`, on port 8200), reads six pages once so nothing is cold, and runs Lighthouse 13 on each: mobile emulation, simulated slow 4G, a CPU four times slower than the machine's.

| Page | Path | Why |
|---|---|---|
| home | `/` | the roadmap's Largest Contentful Paint target |
| blog | `/blogs` | |
| post | `/blogs/a-first-post` | the target again, for a blog post |
| forum | `/forum` | |
| shop | `/shop` | |
| product | `/shop/products/hoodie` | the target again, for a product page |

The result is in `lighthouse-report/` (ignored by git): a `.report.html` to open in a browser and a `.report.json` for each page, and `site.log`, the log of the application's server. A table of the scores and the main numbers is printed, and written into the summary of the CI run when there is one.

In CI the `lighthouse (report)` job does the same on Playwright's Chromium, and keeps `lighthouse-report` for two weeks as an artifact of the run.

| Option | |
|---|---|
| `--site=https://example.com` | Audit a site that is running, instead of starting one. See [a real site](#a-real-site). |
| `--out=folder` | Where the reports go (`lighthouse-report`). Only the reports and the log of an earlier run in it are removed. |
| `--preset=desktop` | Desktop emulation, without the throttling. |
| `--request-timeout=seconds` | How long a page may take to answer completely when it is read before the audit (default `30`). A page that takes longer, or that takes the connection and then says nothing, is a run that could not be made (exit code `2`) and is named. |

`PHP_BIN` names the PHP to start the application with (default `php`), and `CHROME_PATH` the Chrome to use (default: the one that is installed).

## Why no score fails a run

A Lighthouse score moves with the machine it runs on: another run of the same page, minutes later, can differ by several points, and a CI runner is slower and less steady than a laptop. A number that fails a pull request for that reason is noise that people learn to ignore, so no score, no timing and no "estimated savings" fails anything. They are there to be read, and to be compared between runs.

What does fail a run is what the page decides for itself, and answers the same every time:

| Check | Fails when |
|---|---|
| `image-aspect-ratio` | an image is shown at another aspect ratio than its file has (its `width` and `height` attributes, or its CSS, do not match the file) |
| `image-size-responsive` | an image is shown larger than its file, so it is enlarged and blurred |
| `unsized-images` | an image has no `width` and `height`, so the page moves when it loads |
| `http-status-code` | the page answers with an error status |
| the run itself | Lighthouse could not load the page |
| compression (`--site` only) | the page is sent without compression |

The built-in PHP server that is started without `--site` never compresses anything, and never sends a cache header, so what a real web server does for the page cannot be judged there: those are the `document-latency-insight` compression check (which is judged only with `--site`) and the `cache-insight`, which is never judged. The nginx settings for both are in [performance.md](performance.md).

The exit code is `0` when nothing is wrong, `1` when one of the checks above failed (the table is printed first, and the reports are kept), and `2` when the run could not be made: the site did not start or answer, a page did not answer `200`, or a page did not finish answering in time (see `--request-timeout`; the 180 s that the site is given to start is a limit on the whole wait, and no single request is allowed past it). In CI each problem is also an error annotation on the run.

Image oversize (`image-delivery-insight`, "the file is larger than it needs to be for where it is shown") is **not** a check. The estimate depends on the pixel ratio of the emulated phone and moves between Lighthouse versions; it is in the reports, and see below for what it found.

## The first numbers

2026-11-02, a laptop (Windows, PHP 8.4 with OPcache, the built-in server), Lighthouse 13.6.0, mobile, simulated slow 4G, the guest's pages:

| Page | Performance | Accessibility | Best practices | SEO | FCP | LCP | TBT | CLS |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| home | 99 | 100 | 100 | 100 | 1.1 s | 1.1 s | 55 ms | 0 |
| blog | 99 | 99 | 100 | 100 | 1.1 s | 1.1 s | 55 ms | 0 |
| post | 99 | 100 | 100 | 100 | 1.1 s | 1.1 s | 67 ms | 0 |
| forum | 99 | 100 | 100 | 100 | 1.1 s | 1.1 s | 40 ms | 0 |
| shop | 99 | 99 | 100 | 100 | 1.1 s | 1.1 s | 53 ms | 0 |
| product | 99 | 100 | 100 | 100 | 1.1 s | 1.1 s | 54 ms | 0 |

The roadmap's target is a Largest Contentful Paint under 2.5 s on 4G for the home page, a blog post and a product page; on this setup it is 1.1 s for all three. Read it with [what it does not show](#what-it-does-not-show): a site with a few records and no compression is not a site with a thousand.

## What the first run found

It found four things, on pages nobody had looked at this way.

1. **The logo was declared as a different shape from its file** (fixed). `AppLogo.vue` and the sign-in layout gave the image `width="280" height="56"` (5:1), and the file is 1024 x 341 (3:1). The CSS shows it at the right shape, but a browser reserves the room from the attributes before the file has arrived, so the header was laid out for a logo 67 % wider than the one that then appeared. The attributes now match the file. Best practices went from 96 to 100 on every page.
2. **The logo is sent at 1024 px and shown at 96 to 192 px** (open). It is 57 to 63 kB for each colour scheme, and it is in every page. `image-delivery-insight` puts the saving at 54 to 68 KiB on a first visit. It is not fixed here because the same files are the banner in the README, which wants the large size on a screen with a high pixel ratio; the choice is between a second, smaller file for the site and a smaller one for both.
3. **Heading order on the blog and the shop** (open). The titles of the cards on the blog list and on the shop are `h3`, directly under the page's `h1`, with no `h2` between. A screen reader's list of headings has a step missing.
4. **A link whose name does not hold its text** (open). The featured post on the blog list is a link with `aria-label="Read featured blog: A first post"`, and Lighthouse's `label-content-name-mismatch` says the visible text is not part of that name, which a person who speaks to their computer ("click A first post") needs. The axe audit ([accessibility.md](accessibility.md)) fails only on critical problems, so it does not report these.

## A real site

```bash
npm run lighthouse -- --site=https://example.com
```

No application is started; the six paths are read from that address. The post and the product are the records of the seeder (`a-first-post` and `hoodie`), so on another site they are a `404` and the run stops with exit code `2` and says which page. Run it with the paths changed in `PAGES` in `scripts/lighthouse-report.mjs` for a site of your own.

Here the compression check is judged too, since the web server is a real one.

## What it does not show

- **One run each.** Lighthouse's own advice is to take the median of several. A score that differs by a point or two between runs is noise; a change of ten is not. Run it again before believing a single bad number.
- **A small site.** The seeded site has one post, one product and one thread. A list of fifty posts with covers, or a thread with a hundred replies, is heavier than any page here.
- **Pages of a signed-in user.** The cart, the notification count and the staff area are behind a sign-in, and a signed-in user's browser also fetches the rest of the front end in the background ([bundle-budget.md](bundle-budget.md#what-it-does-not-do)).
- **A real web server.** With no compression and no cache headers the page weighs about 1.1 to 1.2 MB, its text sent as written. A first visit to the home page is about 227 kB when the web server compresses ([bundle-budget.md](bundle-budget.md#what-is-measured)), and with the cache headers in [performance.md](performance.md) a second visit fetches almost none of it. Lighthouse's `cache-insight` ("use efficient cache lifetimes", about 1 MB) and `document-latency-insight` are about that.
- **A real network.** The throttling is simulated from a trace taken on the machine; it is Lighthouse's standard, and it is the same for every site it measures, which makes it good for comparing and not for promising a number to a visitor.

## Why `lighthouse` and not `@lhci/cli`

Lighthouse CI (`@lhci/cli`) was the first choice: it brings a configuration file, assertions and a server for the results. 0.15.1 is its last release (June 2025) and it pins Lighthouse 12.6.1. Installed, it added 330 packages and 20 advisories, 13 of them high, none with a fix. `lighthouse` 13 on its own adds 137 packages and 5 moderate advisories (glob and minimatch, through Sentry's bundler plugins, in a package that is only used while Lighthouse runs). Both are development dependencies, and `npm audit --omit=dev`, which CI runs, finds nothing in either case. The script in this repository does the part of Lighthouse CI that is needed here (run the pages, keep the reports, fail on a few checks) in about 430 lines that have tests, and has no server to run.

Lighthouse is in `devDependencies` and is not shipped: the licence audit ([licences.md](licences.md)) does not list it.

## Tests

`scripts/lighthouse-report.test.mjs` runs with `npm run bundle:test`. Lighthouse itself is not run by it (that takes a browser); a stand-in takes its place, and the script is run as `npm run lighthouse` runs it, against a stand-in site:

- which audits fail a run, and that a score, a time, a cache or an image-delivery estimate never does; that compression is judged for a real site only;
- the arguments Lighthouse is given, and that Chrome's sandbox is turned off on a CI runner and only there (with `--disable-dev-shm-usage` there too);
- a trace that never starts (NO_NAVSTART) is run again after the page is read once more;
- reading the numbers out of a report, the two tables, and what a missing number looks like;
- waiting for the site, and what it says when the site never answers or the application stopped; a page that ends by closing the connection is read to its end (Node 24 can crash on one that is left unread, see `scripts/check-install.test.mjs`);
- a server that takes the connection and then stalls, before any answer or half-way through the body: the request is given up on at its deadline, a wait never outlasts its own deadline for it, a stalled request is asked again inside the wait, a port that is held by something that says nothing counts as used, and a page that stalls when it is read before the audit is named and ends the run with `2` before Lighthouse is started;
- the exit codes, the job summary, the annotations, that one page failing does not stop the others, and that an earlier run's reports are removed and nothing else in the folder is.

The start of the application (the part that needs PHP) is not in the tests; `npm run lighthouse` is its test, and CI runs it.
