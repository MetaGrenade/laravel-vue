# Accessibility

axe checks the public pages a visitor opens, the pages a signed-in member opens, and the admin area. A problem it marks critical, serious or moderate fails the check, and so does a link or button whose name leaves out the words shown on it.

> **Status:** built: the audit, the critical problems it found on the public pages, then the serious and moderate ones, and then the same for the signed-in pages and the admin area. **Not built:** see [below](#not-built).

## What is checked

Home, sign in, register, forgot password, pricing, the shop, a product, the cart, checkout after that product is added, the forum and a thread, the blog and a post, support, the member directory, a profile, and the leaderboard.

A member's own pages (30): the dashboard, the forum (a board, a thread, the form for a new thread, the pages for what the member follows), messages, orders, the wishlist, a product and the cart as a member sees them, a blog post with its comments, support and a ticket, a profile, the confirm-password page, and every page under settings (profile, account standing, addresses, API keys, appearance, billing with its invoices and payment methods, Discord notifications, notifications, password, privacy, security).

The admin area (77): every page the control panel has that is made by a request for it, with each kind of list, and the form to create and the form to edit a record where there is one (members, roles and permissions, the audit log, billing, blog posts, categories, tags and comments, the shop, forums, polls, badges, search analytics, support with its tickets, FAQs, teams, templates and rules, the system settings, tokens, trust and safety).

`AccessibilitySignedInFlowSeeder` creates the records for all of them (the smoke suite's member and administrator, the public records, and a few of each thing a table lists, so that a table has rows and the edit form is for a record that exists) in `database/a11y.sqlite`, which is rebuilt from nothing every time the check starts. The member signs in as `avery@example.com`, the administrator as `admin@example.com`, both with the factory's password; each signs in once, and the pages are opened with the cookies of that sign-in. The check fails when axe reports a critical, serious or moderate problem, or finds a link or button whose name leaves out the words shown on it (`label-content-name-mismatch`, a rule axe keeps off unless it is asked for). What axe marks minor does not fail it.

## Running it

PHP 8.4, from the repository root, after `npm run build` and with no Vite dev server running. The check serves the app at `http://127.0.0.1:8000` on its own database and never touches the one in `.env`.

```bash
npm run a11y
```

Set `PHP_BIN` when `php` on the path is not 8.4. It starts the application the way the smoke suite does, so the other options (a browser that is already installed, a site that is already running) are in [e2e-tests.md](e2e-tests.md#running-it). CI runs the same check as the `a11y` job in `.github/workflows/tests.yml`.

## What changed

The signed-in pages and the admin area were read the same way, asked for everything (2026-11-15, 33 member pages and 79 admin pages, minor problems too). Before anything was fixed, 30 of the 31 member pages and every one of the 77 admin pages that answered would have failed the check as it is now. They are fixed, and the check has these pages in it:

- **Controls with no name** (critical): the `…` button that opens a row's menu on the members, roles, permissions, blog posts, forum categories and boards, tickets, FAQs, tokens and reports lists, in a thread and on each post, on the support page; it is "Actions for" the row. Every switch that was beside a heading and had no label of its own (system settings, the Discord pages, plans, polls, badges, assignment rules, templates) is named by the heading. The select-all box and the box on each row of two admin tables, the two reorder arrows of an assignment rule, the filters of the admin ticket list (three selects and two dates), the file pickers on the support pages, two read-only boxes of the two-factor set-up, and the box a blog comment is edited in. A back arrow on three admin pages was a link, with no name, around a button; it is a button that is the link, named "Back to the post", "Back to the thread" or "Back to the tokens".
- **The writing box of the editor** (critical, `aria-input-field-name`): the thread form and the reply boxes had a `textbox` that nothing named. `RichTextEditor` takes a `label` ("Message" unless told, "Post content" when editing a post, "Your reply"), and its preview button, which had only an icon, is named "Preview".
- **A name that leaves out the words on the element** (serious, `label-content-name-mismatch`): the account menu in the header was labelled "Account menu" while it showed the member's nickname, on every page a member opens. It is named by what it shows (the nickname, where there is room for it) and "Account menu" for a screen reader; the picture in it, which only repeats the nickname, is hidden from them. The wishlist button that says "Saved" was named "Remove … from your wishlist"; it is "Saved … to your wishlist".
- **A heading level skipped** (moderate): card titles and the titles of the settings and admin sections were `h3` directly under the page's `h1` on every settings page, on the dashboard, orders, a support ticket and 47 admin pages. `CardTitle` and `HeadingSmall` are `h2` now; `CardTitle as="h3"` is for a card inside a section that has an `h2` of its own. The four figures on the shop's admin dashboard and the ones on a coupon and a gift card were card titles, so headings that read "2"; they are `as="div"`.
- **A link told apart only by its colour** (serious, `link-in-text-block`) inside a sentence, on the product form, the stock history, the forum list and the orders: underlined.
- **Minor**: the header cell of a column of row menus had no text (the three tables that had it now say "Actions" to a screen reader), and the avatar next to a nickname repeated it.

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
- What a page shows only after a click or a failure: dialogs, the editor's menus, a form with its errors, an empty list, a page for a role that is not an administrator (an editor or a moderator sees fewer of the admin pages).
- The admin page of a single order (it is found by an identifier that is different in every database).
- CAPTCHA.

The paths people take through the site, as opposed to the pages they open, are the smoke suite in [e2e-tests.md](e2e-tests.md).

## Testing

`tests/a11y/main-flows.spec.ts` (the public pages) and `tests/a11y/signed-in-flows.spec.ts` (the member and the administrator); both use `tests/a11y/axe.ts`. The run takes about four minutes, 124 pages, one browser at a time: PHP's built-in server answers one request at a time, from one SQLite file.
