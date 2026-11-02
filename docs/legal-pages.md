# Legal pages

Terms, a privacy policy and a cookie policy, written in the admin area, and a banner that asks visitors which cookies they allow.

> **Status:** built: the three pages, the footer links, the sitemap lines, and a choice of necessary cookies or all cookies. **Not built:** see [below](#not-built).

## Using it

**Admin → Legal pages** (the same people who can open system settings). Each page is HTML. A page with no text is not on the site: it is not in the footer, not in the sitemap, and its address answers 404. Saving removes a script, a style, a frame and a `javascript:` link. The date on a page changes only when its text changes.

The footer lists the pages that have text. The banner sits at the bottom of the public pages until the visitor chooses. **Necessary only** keeps the cookies that sign someone in and that remember this choice. **Allow all** is the only choice that turns optional cookies on. The theme and side menu cookies are **preference cookies**: the front end sets them only when a visitor changes those settings, whichever choice they made, and the banner and the cookie policy say so. The choice lasts 180 days, is not readable by a script on the page, and is sent with ordinary visits.

Nothing optional is loaded today. `CookieConsent::allowsOptional()` is the check a later script should use. Paying (Stripe, Tebex) is not optional and does not wait for this choice.

A page that is only a script is not published. A setting that is not the expected shape is treated as empty.

Viewing the site as a customer does not save a choice. That view cannot change anything.

The page is shown with the rich-text styles the blog and the editor use: headings that step down in size, lists, quotes, links and tables.

## Starting text

```bash
php artisan db:seed --class=LegalPagesSeeder
```

gives the terms, the privacy policy and the cookie policy a starting text, so the pages are not blank. A plain `php artisan db:seed` for local development runs it too, but a production site should run only this one. `php artisan metaforge:install --legal-pages` runs it as part of setting a site up ([install.md](install.md#legal-pages)). It is safe on a live site: a page that already has text is left exactly as it is, with its date, so running it again changes nothing, and a page you emptied gets its starting text back. When it finishes it lists what is left for you to fill in.

The text is in `database/seeders/legal/` (`terms.html`, `privacy.html`, `cookies.html`). It is written for what this software does: the cookies it sets and for how long, the personal data it keeps (account, profile, posts, orders, API keys, IP address and browser details, searches), the services it talks to (Stripe, Tebex, Pusher, the sign-in providers, Discord), and what **Settings → Privacy** gives a member (an export as a ZIP file, and an erasure request that a person answers within 30 days). It goes through the same clean-up as anything saved in the form. It has no tables, because a table is too narrow to read on a phone.

**Filled in from the site when you seed:** the site's name (`SEO_SITE_NAME`, or `APP_NAME`), the session cookie's name and lifetime, and how long searches (`SEARCH_QUERY_RETENTION_DAYS`) and API usage counts (`BILLING_USAGE_RETENTION_MONTHS`) are kept. If one of them is 0 or less, pruning is off and nothing is deleted, so the page says there is no fixed period instead of naming one. They are read once, so if you change one of those settings later, change the page too.

**Left for you,** as a `[Bracketed item]`:

| Item | What to put there |
| --- | --- |
| `[Operator name]` | The person or company that runs the site. |
| `[Operator address]` | Their postal address. |
| `[Contact email]` | Where people write about the terms, their data or a refund. |
| `[Minimum age]` | The youngest age that may have an account, in years. |
| `[Refund period]` | How long after buying someone may ask for a refund, for example 14 days. |
| `[Governing law]` | The country or state whose law applies to the terms. |
| `[Courts]` | The place whose courts hear a dispute. |
| `[Hosting provider]` | Who hosts the site. |
| `[Email provider]` | The service that sends the site's email. |
| `[Record keeping period]` | How long orders and invoices are kept, as tax law in your country requires. |

Each page opens with a note that it is a starting point and not legal advice. Delete it when you have read the page, filled in the brackets, and checked every sentence against how you run the site: whether you use Stripe, Tebex or both, who sends your email, where you host, what your country requires. The text uses the words of data protection law of the GDPR kind (controller, legitimate interests, supervisory authority) and says nothing about any other kind. Have a lawyer read it before you rely on it.

**Keep it true.** When you add something that sets a cookie, loads a script from another company, or keeps a new kind of personal data, change the pages in the same change, and the starting text if you want it to stay a good start. A test keeps the cookie list tied to the code (the names, and how long each lasts), every link to a page that exists, and the bracketed items to the list in `LegalPagesSeeder::PLACEHOLDERS`.

## Not built

- **A tick at registration** that someone accepted the terms, and a history of each version.
- **A switch for each kind of cookie** beyond necessary and all.
- **Loading a third-party script.** The choice is stored. No analytics script is on the site.
- **Other languages.** The screens use translation keys. English is the only language shipped.
- **A policy that is legally yours.** The pages are empty until someone writes them or runs the seeder. The starting text is a draft for the operator to fill in and have checked; it is not legal advice.

## Testing

`tests/Feature/Legal/LegalPagesTest.php`: an empty page is 404, a script and a `javascript:` link are removed, a page with no text is left out of the footer data and the sitemap, and publishing rebuilds the sitemap. An editor cannot open the form. Someone who can look at system settings but not change them cannot save. A guest can save a choice; a nonsense choice is refused; viewing as a customer does not set the cookie. Optional cookies are allowed only after **Allow all**.

`tests/Feature/Legal/LegalPagesSeederTest.php`: all three pages are added and on the site; a page that has text keeps its text and its date; running it again changes nothing, and a page that was emptied gets its text back; the command names what is left to fill in; the saved pages keep every word and their structure; the site name is made safe and the retention periods come from the configuration; a period is not named where pruning is off, and what the text says about keeping searches and usage is what the prune jobs do; every bracketed item is in the list and every listed item is used; every page opens with the note; a name the text asks for with no value is an error; every link goes to a route; the cookie list has every cookie the software sets, with the lifetimes the code gives them, and the front end sets no other.
