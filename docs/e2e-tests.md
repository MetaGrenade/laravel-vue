# Browser tests

The paths a person takes through the site, driven in a real browser with [Playwright](https://playwright.dev): registering and signing in, a forum thread and a reply, a comment, a support ticket, the cart, an order that costs nothing, the admin area. Roadmap section 10 asks for "Playwright smoke suite green on the main flows"; this is it.

The feature tests (`php artisan test`) call the server and read what it sends. They cannot show that the page the server sends and the scripts that run in it work together: a button that does nothing, a form that posts to the wrong address, an editor that never loads. These tests can, and they are few on purpose: each one is a path somebody takes every day, and a failure in one means the site is broken, not that a detail changed.

There are two browser suites. They share the server script and the browser, and have a database each.

| Suite | Config | Database | Asks |
|---|---|---|---|
| Smoke (`npm run e2e`) | `tests/e2e/playwright.config.ts` | `database/smoke.sqlite`, from `SmokeFlowSeeder` | Does it work? |
| Accessibility (`npm run a11y`, [accessibility.md](accessibility.md)) | `tests/a11y/playwright.config.ts` | `database/a11y.sqlite`, from `AccessibilityFlowSeeder` | Can everybody use it? |

## What the smoke suite does

`tests/e2e/smoke.spec.ts`:

| Who | Path |
|---|---|
| A visitor | Home to the shop, the forum (a board, a thread) and the blog (a post) by their links |
| A visitor | The search box finds a post by its title and opens it; the search page lists what matches |
| A visitor | The cookie banner is answered once and stays answered after a reload |
| A visitor | An address that is not there answers 404 on a page of the site |
| A visitor | Registers, is signed in, signs out and signs in again |
| A member | Signs in and out; a wrong password is refused and says so |
| A member | Starts a forum thread and replies to it |
| A member | Comments on a blog post |
| A member | Opens a support ticket and sees it in the list |
| A member | Is refused the admin area with a 403 on a page of the site |
| A visitor | Puts a product in the cart, changes how many, takes it out again and reaches checkout |
| A visitor | Orders a free download without paying: no payment provider is involved, and the order is shown as paid |
| An administrator | Opens the admin area and finds a member |

The records come from `SmokeFlowSeeder`: the accessibility records (a member, a hoodie that costs 10.00, a board with a thread, a blog post), an administrator, the roles and permissions, the support categories, and a free digital product. The two people sign in with the factory's password, `password`; the accounts exist only in this throwaway database. The administrator is given the role by name: `RolePermissionSeeder` gives it to the account with id 1, which a MySQL or PostgreSQL database that has held users before does not have (they keep counting after a rollback). `SmokeFlowSeederTest` fails in `php artisan test`, on all three databases, when the seeder stops giving the suite what it asks for, so that is found in a minute and not in the browser job.

## Running it

PHP 8.4 and Node, from the repository root. Once:

```bash
npm run build
npx playwright install chromium
```

Then:

```bash
npm run e2e
```

- **A build is needed, and a Vite dev server must not be running.** While `public/hot` exists the pages ask the dev server for their scripts, which a test browser cannot use. The script says so and stops.
- **Set `PHP_BIN`** when `php` on the path is not 8.4.
- **`E2E_BROWSER_CHANNEL=chrome`** (or `msedge`) runs the browser that is installed instead of the Chromium Playwright downloads.
- **One test:** `npm run e2e -- -g "free product"`. **See what it does:** `npm run e2e -- --headed`.
- **A site that is already running:** `E2E_BASE_URL=http://127.0.0.1:8000 npm run e2e` starts nothing and rebuilds nothing, so the people and records in `SmokeFlowSeeder` must exist there. The suite registers accounts and posts, so never point it at a real site.

A failed test leaves a screenshot and a trace in `test-results/e2e` (`npx playwright show-trace <trace.zip>`), and the log of the server, with every request and whatever the application reported, is `test-results/server-smoke.log`. In CI the report, those folders and `storage/logs` are kept for a week as the `e2e-report` artifact of the `e2e (Playwright smoke suite)` job.

## How the application is started

`tests/e2e/serve.mjs` starts the application for both suites, and is the only place that decides what they run against:

- **The database is built from nothing at every start** (`migrate:fresh` and the seeder) in a file in `database/` named by the config, and it refuses any other name. The `.env` database is never reached, whatever `.env` says, and two things make sure of it:
  - A cached configuration (`php artisan config:cache`) is read instead of `.env` and of every setting the script makes, so `migrate:fresh` would run against the database it names. The script points the configuration, route and event caches at files that never exist, so the application reads its settings from the source, as in a new checkout.
  - Before anything is dropped, the script asks Laravel which database it will use (`php artisan db:show --json`) and stops, without touching anything, unless that is the suite's own file. This holds for whatever else may one day override the settings.
- **Nothing leaves the machine.** Mail goes to the array driver, broadcasting to the log, Stripe's keys are blank and search uses the database. The session cookie is not pinned to a domain, because the server is `http://127.0.0.1`.
- **The server's output is a file**, because PHP's built-in server writes a line for every request.
- The built-in server answers one request at a time, so a click can wait behind the scripts a page asks for after it has drawn (`Vite::prefetch`, see [bundle-budget.md](bundle-budget.md)). The expectations wait 15 seconds for that; the whole suite takes about a minute.

`tests/e2e/server.ts` is the part of the configs the two suites share: the address, the browser and the `webServer` entry.

## Writing a test

- **Independent.** Each test signs in for itself and makes the records it needs, with names that are new for every run (`unique()`), so the tests run in any order and a site kept between runs never says "already taken".
- **Look at the saved thing, never at what was typed.** A test that types a reply and then looks for the text finds it in the box whether or not anything was sent. The first version of the reply test did, and passed with no request made. Look for the post (`[id^="post-"]`), the comment (`[id^="comment-"]`), the ticket in the list, or an input that was emptied because the form was saved.
- **Use what a person uses**: roles and labels (`getByRole`, `getByLabel`), not classes. The rich text editor is the one exception, since it is an editable region with no label (`typeInEditor`).
- **Check a new test can fail.** Break the code it depends on (an `abort(500)` at the top of the controller method) and run the test with `-g`: it must go red.

## Not covered

- Paying: Stripe and Tebex are external, so a paid order stops at the checkout page. The order that costs nothing takes the same path up to the provider, and the payment layers have their own tests with fake providers.
- Search through Meilisearch (`tests/Live/Meilisearch`), mail, two-factor sign-in, file uploads, the live parts that need a websocket server.
- A phone-width screen, or any browser but Chromium.
- The admin area beyond opening it: its forms are covered by the feature tests.

## Not built

- The suite does not run against MySQL or PostgreSQL: it uses SQLite, as the accessibility audit does. The feature tests run on all three.
- Vitest unit tests and coverage reporting are separate items in the same roadmap section.
