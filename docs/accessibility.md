# Accessibility

axe checks the public pages a visitor opens, the pages a signed-in member opens, and the admin area, each in the light theme and in the dark one, and 28 things those pages show only after a click or a failure (a menu, a dialog, a form with its errors). The same pages are opened again at the width of a small phone, 320 pixels, where the page must not scroll sideways. A problem axe marks critical, serious or moderate fails the check, and so does a link or button whose name leaves out the words shown on it.

> **Status:** built: the audit, the critical problems it found on the public pages, then the serious and moderate ones, then the same for the signed-in pages and the admin area, then colour contrast, which the check had never measured, then the states a page is in after a click or a failure, and then the pages at a phone's width. **Not built:** see [below](#not-built).

## What is checked

Home, sign in, register, forgot password, pricing, the shop, a product, the cart, checkout after that product is added, the forum and a thread, the blog and a post, support, the member directory, a profile, and the leaderboard.

A member's own pages (32): the dashboard, the forum (a board, a thread, the form for a new thread, the pages for what the member follows), messages, orders, the wishlist, a product and the cart as a member sees them, a blog post with its comments, support and three tickets (one for each colour of a status and a priority), a profile, the confirm-password page, and every page under settings (profile, account standing, addresses, API keys, appearance, billing with its invoices and payment methods, Discord notifications, notifications, password, privacy, security).

The admin area (79): every page the control panel has that is made by a request for it, with each kind of list, and the form to create and the form to edit a record where there is one (members, roles and permissions, the audit log, billing, blog posts, categories, tags and comments, the shop, forums, polls, badges, search analytics, support with its tickets, FAQs, teams, templates and rules, the system settings, tokens, trust and safety).

The states after a click or a failure (28, `tests/a11y/states-flows.spec.ts`): a visitor's search dialog, colour theme menu, sign-in with a wrong password, register with a form that is wrong, and forgot password; a member's account menu, notifications, the menu of a post, the password form, the profile form and the form for a new thread each with their errors, the dialog that deletes the account, the dialog that adds an address (empty and with its errors) and the one that creates an API key; an administrator's row menu on the members, blog posts, tickets, roles and forum categories lists, the dialog that asks before a member is deleted, and the form for a new blog post, role, poll, FAQ, product, coupon and plan sent with nothing in it. Each case opens the page, does what a person does, waits for what it shows (the dialog, the menu, the message under a field) and then runs the check below with that on the screen.

At a phone's width (`tests/a11y/phone-flows.spec.ts`): all the pages above except checkout (16 public, 32 member, 79 admin) at 320 by 800 pixels, and six things a phone opens: the navigation menu, the search and the cart for a visitor, and the navigation menu, the notifications and the account menu for a member. The lists of pages are in `tests/a11y/pages.ts`, which the three specs that open pages share.

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

## Colour contrast

The check had never failed on a colour. Every page has a backdrop (`bg-page::before`, two faint glows and a dot grid, 30rem tall) that is an absolutely positioned pseudo-element, and axe will not say what the background of text under such an element is: it answered "pseudoContent", unknown, for every piece of text on every page, in both themes, and unknown is not a failure. The dark theme looked clean for the same reason (a first scan of it, 123 pages, found nothing at all).

`tests/a11y/axe.ts` now runs axe's colour check with `ignorePseudo`, so it measures text against the colour of the surface and leaves the glows out. The first scan that measured anything (2026-11-15) found 472 failing pieces of text on 92 pages in the light theme and 15 on 10 pages in the dark one. They are fixed:

- **The section headings of the settings and admin menus** (436 of the 472, every page of both areas): `text-muted-foreground/80`, 3.5:1; the colour without the fade, 5.2:1.
- **Success, destructive and info text on a tint of itself** (a badge, an alert, a figure's change): the light theme's green (`--success`) is 3.6:1 on its own 10% tint, the red 3.7:1 and the blue 4.0:1. The three are darker (`152 64% 25%`, `0 72% 42%`, `201 90% 31%`: white text on each, as a fill, is 6.3:1, 6.5:1 and 6.4:1). In the dark theme the red is lighter (`0 72% 68%`); a fill of it, the button and the badge, is that red at 60%, which white text clears (6.3:1), and the one other solid fill (a chosen answer in the support FAQ) now says so.
- **White text on a light button**: "Save team" and "Save template" were `variant="secondary"` with `text-white`, 1.1:1 in the light theme. They are plain secondary buttons.
- **The time under a message of your own** in a support ticket: 75% opacity on the indigo bubble was 4.2:1; it is 90%.
- **The warning orange**, 3.9:1 on its own tint in the light theme (a medium-priority badge): `--warning` is darker (`30 92% 30%`, white text on it as a fill is 6.3:1).
- **The legend of a chart** in the dark theme: the chart library gives its labels a grey of its own (`#6c778c`, 3.8:1 on a dark card) that nothing outside it can change by a variable. A rule in `app.css` gives them the muted text colour.

The seeded tickets had a priority picked at random by the factory, so which badge a ticket page showed (and so whether it failed) changed each time the database was made; the seeder now makes three tickets, one for each colour of a status and a priority, and the check opens each. Both themes are measured by one check on one page: the page is loaded, axe runs in the light theme, `prefers-color-scheme` is switched to dark (the theme follows it until someone picks one, and the check fails if the page does not turn dark, so that it cannot measure the light theme twice and call it the dark), and the colour check runs again. The browser is 1280 by 3000, because axe cannot measure text that is partly under the sticky header it was scrolled up to or that is below the bottom of the screen; that was 665 of the 874 pieces of text it could not measure in the dark theme. The size is set on the project in `playwright.config.ts`, after the device (the device brings a 720 pixel viewport of its own that wins over a top-level one), and every page fails if the browser is not that size. The check also fails when axe can measure less than 60% of the text on a page, which is how it would show that it had gone blind again.

What it still does not measure: text on a photograph (a blog cover, 92 pieces over the 123 pages), on a gradient (66), and a few under another element or too short for axe to judge. Over 10,000 pieces per theme are measured and about 200 are not.

The glows are left out of the measurement. At the very top corner of the page they move the background by about a fifth of its luminance, and the muted text colour is 5.2:1 on the surface; the glows fade within a few hundred pixels.

## What a page shows after a click or a failure

Every page above was opened and read as it loads. A menu that is closed, a dialog that has not been asked for and a form that has not been sent are not on it, and a person meets them all. The 28 cases of `states-flows.spec.ts` open them (2026-11-15), and axe found two kinds of fault, both fixed:

- **The search dialog had no name** (serious, `aria-dialog-name`): the palette that `Ctrl+K` opens, on every page, was a dialog that a screen reader announced as "dialog". It has a title, "Search", and a description, both for a screen reader only.
- **Six row menus whose items were not items** (critical, `aria-required-children` and `aria-required-parent`): on the members, blog posts and support lists, a menu entry that goes to a page was a `Link` around the `DropdownMenuItem`, so the `menu` held a link and the `menuitem` sat in a link, and a screen reader read neither as a menu. It is the item that is the link now (`<DropdownMenuItem as-child><Link …>`): Edit and Moderate on a member, Edit on a blog post, View conversation and Edit on a ticket, and Edit on a FAQ.

The error states needed one thing of the test. The browser stops a form that has a required field empty before it is sent, so the case turns that off (`noValidate`) and the server's own messages are what is on the screen, and it waits for one (`p.text-destructive` with text in it) before it checks.

A menu that is open is modal: reka-ui sets `aria-hidden` on everything behind it, which is the whole page, so axe also reports that the page has no `main`, no heading and content outside a landmark, and focusable elements in a hidden region. They are what an open menu does to the page behind it, not faults in it, and the rules that say so (`aria-hidden-focus`, `landmark-one-main`, `page-has-heading-one`, `region`) are turned off in the six cases that have a menu open (`menuOpen`), and in no other. The menu itself is still checked by every other rule.

## At a phone's width

Every page above was opened at 1280 pixels, and what does not fit at 320 is not on a page at that size at all. 320 is the width WCAG's reflow rule (1.4.10) names: a small phone, and a 1280 pixel screen zoomed to 400%. The browser is not an emulated phone, which would stretch the layout to fit whatever sticks out; it is a narrow window, so that anything wider than the screen makes the page scroll sideways. `phone-flows.spec.ts` fails a page that does (and names the elements that stick out), and a page where axe finds a critical, serious or moderate problem. It also turns on `target-size` (a control smaller than 24 by 24 pixels with no room around it, WCAG 2.2, 2.5.8), which axe keeps off by default. Colours are not measured again: they are the same at every width.

The first scan (2026-11-15, the 127 pages at 375 and again at 320) found that 1 page scrolled sideways at 375 and 110 at 320, and that a table could not be scrolled with the keyboard on 6 pages at 375 and 7 at 320. The spec then found more, with `target-size` on. They are fixed:

- **The header of every signed-in page was 16 pixels too wide at 320** (108 pages): the logo's right margin, the gaps and the account button's margin took room that the four icons after it did not leave. They are smaller below `sm`. The notifications menu was 22rem (352 pixels) wide whatever the screen; it is now never wider than the room there is.
- **A table that scrolls sideways and has nothing in it the keyboard can stop on** (serious, `scrollable-region-focusable`) on a member's invoices, the admin's invoices, a gift card's history, search analytics, tokens and trust and safety: a keyboard could not scroll it, so the columns off the edge could not be read. `Table` becomes a tab stop, named "Scrollable table", when it scrolls and has no link, button or field inside, and only then; a table that fits, or one with links in it, is not one more thing to tab through.
- **Two cards that would not shrink** (search analytics, 406 pixels; the new role form, 349): a grid with one column takes the width of its widest content, a table or a permission name with no space in it. The grid is `grid-cols-1` below `lg`, the permission names wrap, and the two export buttons wrap.
- **Controls smaller than 24 pixels** (serious, `target-size`): the close button of every dialog and every sheet was 16 by 16 (it has room around it now; the icon has not moved), and the "Subscribe via RSS" and "Leaderboard" links on the forum page, which wrap under each other at this width, were 20 pixels apart.
- **A button inside a link** on a thread ("Post Reply"): two stops for the keyboard, and HTML does not allow it. A search of the code found 11 more, on the roles and permissions, blog posts, support, tokens, the cart and the thread's "Go To Top". The button is the link now (`<Button as-child><Link …>`), and ESLint reports a button inside a link (`vue/no-restricted-syntax`, in `eslint.config.js`).
- **The account menu had a picture in it** (critical, `aria-required-children`): a `menu` may hold only menu items, and the avatar in its header was an `img`. The avatar beside a nickname is decoration (`alt=""`, hidden from a screen reader), as it already was in the header.

The first scan asked for `target-size` in a way that did nothing: `AxeBuilder#disableRules` replaces the `rules` of the options it is given, so the rule was never on (nor was `label-content-name-mismatch`), and the four rules that `menuOpen` turns off stayed on. The sideways scrolling and the tables were found without them. Everything about the rules is now in the one `rules` object (`expectFitsAPhone` in `axe.ts`); do not add `disableRules` to a run that has `rules`.

## Not built

- Minor problems: the check does not fail on what axe marks minor.
- At a phone's width: colour contrast, the dialogs and forms of the 28 states, and a screen taller than wide turned on its side. The page is not checked at 200% text size.
- Text over a photograph or a gradient, and text in the very top corners of the page, where the glow behind it is strongest: colour contrast there is not measured.
- The states beyond the 28: the editor's own menus (the toolbar, the mention list), the other dialogs and confirmations of the admin area, the errors of the other forms, and the list of notifications with something in it (the member's has none). An empty list, and a page for a role that is not an administrator (an editor or a moderator sees fewer of the admin pages).
- The admin page of a single order (it is found by an identifier that is different in every database).
- CAPTCHA.

The paths people take through the site, as opposed to the pages they open, are the smoke suite in [e2e-tests.md](e2e-tests.md).

## Testing

`tests/a11y/main-flows.spec.ts` (the public pages), `tests/a11y/signed-in-flows.spec.ts` (the member and the administrator), `tests/a11y/states-flows.spec.ts` (what they show after a click or a failure) and `tests/a11y/phone-flows.spec.ts` (the pages and six menus at 320 pixels); all four use `tests/a11y/axe.ts` and the pages in `tests/a11y/pages.ts`, and the three that sign in use `tests/a11y/session.ts`. The run takes about 13 minutes: 289 cases (128 pages and 28 states, each in both themes, and 133 at a phone's width), one browser at a time, because PHP's built-in server answers one request at a time, from one SQLite file. `npx playwright test --config=tests/a11y/playwright.config.ts phone-flows` runs one spec.
