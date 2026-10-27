# Accessibility

axe checks the public pages a visitor opens. A problem it marks critical fails the check.

> **Status:** built: the audit, and the critical problems it found on those pages. **Not built:** see [below](#not-built).

## What is checked

Home, sign in, register, forgot password, pricing, the shop, a product, the cart, checkout after that product is added, the forum and a thread, the blog and a post, support, the member directory, a profile, and the leaderboard.

`AccessibilityFlowSeeder` creates those records in `database/a11y.sqlite`. Rebuilding runs only when that file is the database. The check fails when axe reports a critical problem.

## Running it

PHP 8.4, from the repository root. `A11Y_PREPARE=1` rebuilds `database/a11y.sqlite` and serves the app at `http://127.0.0.1:8000`.

```bash
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/a11y.sqlite" DB_URL= APP_URL=http://127.0.0.1:8000 A11Y_PREPARE=1 npx playwright test --config=tests/a11y/playwright.config.ts
```

Set `PHP_BIN` when `php` on the path is not 8.4. CI runs the same check as the `a11y` job in `.github/workflows/tests.yml`.

## What changed

The forum page had a second `main` inside the page's own main landmark. That inner region is a `div`. The support page's ticket category filter had no accessible name. It is labelled "Ticket category".

## Not built

- Fixes for problems axe does not mark critical, including colour contrast.
- Signed-in pages and the admin area.
- The Playwright smoke suite (an M7 item).
- CAPTCHA.

## Testing

`tests/a11y/main-flows.spec.ts`.
