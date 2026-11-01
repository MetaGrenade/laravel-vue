# Accessibility

axe checks the public pages a visitor opens. A problem it marks critical fails the check.

> **Status:** built: the audit, and the critical problems it found on those pages. **Not built:** see [below](#not-built).

## What is checked

Home, sign in, register, forgot password, pricing, the shop, a product, the cart, checkout after that product is added, the forum and a thread, the blog and a post, support, the member directory, a profile, and the leaderboard.

`AccessibilityFlowSeeder` creates those records in `database/a11y.sqlite`, which is rebuilt from nothing every time the check starts. The check fails when axe reports a critical problem.

## Running it

PHP 8.4, from the repository root, after `npm run build` and with no Vite dev server running. The check serves the app at `http://127.0.0.1:8000` on its own database and never touches the one in `.env`.

```bash
npm run a11y
```

Set `PHP_BIN` when `php` on the path is not 8.4. It starts the application the way the smoke suite does, so the other options (a browser that is already installed, a site that is already running) are in [e2e-tests.md](e2e-tests.md#running-it). CI runs the same check as the `a11y` job in `.github/workflows/tests.yml`.

## What changed

The forum page had a second `main` inside the page's own main landmark. That inner region is a `div`. The support page's ticket category filter had no accessible name. It is labelled "Ticket category".

## Not built

- Fixes for problems axe does not mark critical, including colour contrast.
- Signed-in pages and the admin area.
- CAPTCHA.

The paths people take through the site, as opposed to the pages they open, are the smoke suite in [e2e-tests.md](e2e-tests.md).

## Testing

`tests/a11y/main-flows.spec.ts`.
