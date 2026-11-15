# Accessibility

axe checks the public pages a visitor opens. A problem it marks critical, serious or moderate fails the check, and so does a link or button whose name leaves out the words shown on it.

> **Status:** built: the audit, the critical problems it found on those pages, and then the serious and moderate ones. **Not built:** see [below](#not-built).

## What is checked

Home, sign in, register, forgot password, pricing, the shop, a product, the cart, checkout after that product is added, the forum and a thread, the blog and a post, support, the member directory, a profile, and the leaderboard.

`AccessibilityFlowSeeder` creates those records in `database/a11y.sqlite`, which is rebuilt from nothing every time the check starts. The check fails when axe reports a critical, serious or moderate problem, or finds a link or button whose name leaves out the words shown on it (`label-content-name-mismatch`, a rule axe keeps off unless it is asked for). What axe marks minor does not fail it.

## Running it

PHP 8.4, from the repository root, after `npm run build` and with no Vite dev server running. The check serves the app at `http://127.0.0.1:8000` on its own database and never touches the one in `.env`.

```bash
npm run a11y
```

Set `PHP_BIN` when `php` on the path is not 8.4. It starts the application the way the smoke suite does, so the other options (a browser that is already installed, a site that is already running) are in [e2e-tests.md](e2e-tests.md#running-it). CI runs the same check as the `a11y` job in `.github/workflows/tests.yml`.

## What changed

The forum page had a second `main` inside the page's own main landmark. That inner region is a `div`. The support page's ticket category filter had no accessible name. It is labelled "Ticket category".

The check used to fail only on critical problems, and nobody had read the rest. Asked for everything on the same pages (2026-11-15), axe found these, and they are fixed:

- **Positive `tabindex` values** (serious) on the sign-in form (1 to 5, one of them on a `div`) and on the register form (1 to 6). A value above 0 puts an element ahead of everything that has none, so the form took the keyboard before the skip link and the header. The forms follow the order of the page now. On sign-in that puts "Forgot password?" between the email and the password, where it is on the screen.
- **A heading level skipped** (moderate) on pricing, the cart, checkout and support: card titles are `h3` directly under the page's `h1`. `CardTitle` takes an `as` prop (`h2`, `h3` or `h4`, `h3` unless told otherwise), and those pages ask for `h2`. On the support page the FAQ's headings moved up a level with its title.
- **A name that leaves out the words on the element** (serious, `label-content-name-mismatch`): a member's card in the directory and in the leaderboard was a link labelled "View Avery Cole's profile", and the cart button in the header was "Open cart" with a number on it. The cards are named by what is on them; the cart button is named "Open cart, 3 items", and the badge, which says the same, is hidden from screen readers.
- **Two navigation landmarks with no name** (moderate) on the blog and in a thread: the page numbers above and below a list. They are "Pagination, top" and "Pagination, bottom", and so are the two on a forum board, which has the same pair (the check does not open a board).
- **A picture that repeats the words beside it** (minor): the avatar next to a member's name. It is decoration (`alt=""`, and the avatar component gives such a picture `role="presentation"`, which reka-ui's `role="img"` would otherwise contradict).

## Not built

- Minor problems: the check does not fail on what axe marks minor.
- Colour contrast in the dark theme: the check runs in the light one.
- Signed-in pages and the admin area.
- CAPTCHA.

The paths people take through the site, as opposed to the pages they open, are the smoke suite in [e2e-tests.md](e2e-tests.md).

## Testing

`tests/a11y/main-flows.spec.ts`.
