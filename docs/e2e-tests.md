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
| A visitor | A blog post is shown with the text styles of the editor, so a list has its bullets (a post styled with a class that nothing defines shows none) |
| A visitor | In a blog post the headings step down in size under the page title, and the bar beside a quote can be seen (3 to 1 against what it is drawn on), in light and dark |
| A visitor | The search box finds a post by its title and opens it; the search page lists what matches |
| A visitor | The cookie banner is answered once and stays answered after a reload |
| A visitor | On a phone, the end of the page (the footer) is not hidden under the cookie banner |
| A visitor | An address that is not there answers 404 on a page of the site |
| A visitor | Registers, is signed in, signs out and signs in again |
| A visitor | The sign-in pages (log in, register, forgot password) show their words and not the names of language keys, and so does a signed-in member's confirm-password page: they are sent their words by their routes only ([i18n.md](i18n.md)) |
| A member | Seven settings pages (profile, password, security, API keys, notifications, billing, addresses) show the heading and the navigation of their frame and no language key, and the header's account menu and cart sheet have their words ([i18n.md](i18n.md)). Taking `translations:settings` off the routes fails it at the heading |
| A member | Signs in and out; a wrong password is refused and says so |
| A member | Turns on a Discord notification, saves, and a reload shows it kept: the switch on that page has to carry its value to the form and back |
| A member | Turns two-factor on with the code an authenticator app would show, then presses Disable: the site sends the page that asks for the password and two-factor stays on behind it, a wrong password is refused and says so, the right one brings the member back to the Security page with it still on, and the same button then switches it off ([two-factor.md](two-factor.md)). Taking the password check off the route fails it at the first press |
| A member | Turns two-factor on and is shown eight recovery codes (with the words that say it is only now); a reload hides them, says eight are left and the page source has none of them; **Show recovery codes** sends the member to the page that asks for the password, a wrong one is refused and the right one brings the same eight back for that one load; **Hide recovery codes** puts them away without leaving the page; and a new set is shown once and is not the first ([two-factor.md](two-factor.md#seeing-the-recovery-codes)). Sending the codes with every load, or taking the password check off the route, fails it |
| A member | Is signed in in a second browser (a second context), changes the password in the first, and the second one's next click lands on the sign-in page while the first carries on ([two-factor.md](two-factor.md#browser-sessions)). Taking `AuthenticateSession` out of the web group fails it |
| A member | The same two browsers: **Sign out other browsers** shows two sessions in the list, a wrong password is refused and signs nobody out, the right one shows "Other browsers signed out", leaves one session in the list and sends the second browser to the sign-in page. Taking the password check out, the row clearing or the field's connection to the form fails it |
| A member | Types a new email address on the profile page: a password field appears (and goes again when the old address is typed back, with spaces and in capitals); saving without the password and with a wrong one is refused and a reload shows the old address; the right one saves, and a reload shows the new address ([two-factor.md](two-factor.md#changing-the-email-address)). Taking the check off the request, or the field off the page, fails it |
| A member | Starts a forum thread and replies to it |
| A member | Comments on a blog post |
| A member | Opens a support ticket and sees it in the list |
| A member | Is refused the admin area with a 403 on a page of the site |
| A visitor | Puts a product in the cart, changes how many, takes it out again and reaches checkout |
| A visitor | Orders a free download without paying: no payment provider is involved, and the order is shown as paid |
| An administrator | Opens the admin area and finds a member |
| An administrator | Adds a Discord role mapping and flips its switches: the form sends what the switches show, the table shows what was saved, and a flipped switch is saved (the roles of the Discord server come from Discord, so the test answers that one request itself) |

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
- The built-in server answers one request at a time, so a click can wait behind the scripts a page asks for after it has drawn (`Vite::prefetch`, which only a signed-in user's page has, see [bundle-budget.md](bundle-budget.md)). The expectations wait 15 seconds for that; the whole suite takes about a minute.

`tests/e2e/server.ts` is the part of the configs the two suites share: the address, the browser and the `webServer` entry.

## Writing a test

- **Independent.** Each test signs in for itself and makes the records it needs, with names that are new for every run (`unique()`), so the tests run in any order and a site kept between runs never says "already taken". A test that changes how a member signs in (two-factor) registers a member of its own: with two-factor on every sign-in asks for a code, so a test that failed half way would leave the member the other tests use unable to get in.
- **Look at the saved thing, never at what was typed.** A test that types a reply and then looks for the text finds it in the box whether or not anything was sent. The first version of the reply test did, and passed with no request made. Look for the post (`[id^="post-"]`), the comment (`[id^="comment-"]`), the ticket in the list, or an input that was emptied because the form was saved.
- **Use what a person uses**: roles and labels (`getByRole`, `getByLabel`), not classes. The rich text editor is the one exception, since it is an editable region with no label (`typeInEditor`).
- **Check a new test can fail.** Break the code it depends on (an `abort(500)` at the top of the controller method) and run the test with `-g`: it must go red.

## Not covered

- Paying: Stripe and Tebex are external, so a paid order stops at the checkout page. The order that costs nothing takes the same path up to the provider, and the payment layers have their own tests with fake providers.
- Search through Meilisearch (`tests/Live/Meilisearch`), mail, the code that two-factor asks for at sign-in (turning it on and off is covered), file uploads, the live parts that need a websocket server.
- Sessions that are not in the database (Redis, files): the suite runs on the database driver, where the button also deletes rows. That the button signs the other browsers out by itself, and that the page shows no list, are covered by the feature tests (`SignOutOtherBrowsersTest`).
- A phone-width screen, or any browser but Chromium.
- The admin area beyond opening it and the Discord role mappings page: its forms are covered by the feature tests.

## Not built

- The suite does not run against MySQL or PostgreSQL: it uses SQLite, as the accessibility audit does. The feature tests run on all three.
- Vitest unit tests and coverage reporting are separate items in the same roadmap section.
