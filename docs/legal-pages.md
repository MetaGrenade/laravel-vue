# Legal pages

Terms, a privacy policy and a cookie policy, written in the admin area, and a banner that asks visitors which cookies they allow.

> **Status:** built: the three pages, the footer links, the sitemap lines, and a choice of necessary cookies or all cookies. **Not built:** see [below](#not-built).

## Using it

**Admin → Legal pages** (the same people who can open system settings). Each page is HTML. A page with no text is not on the site: it is not in the footer, not in the sitemap, and its address answers 404. Saving removes a script, a style, a frame and a `javascript:` link. The date on a page changes only when its text changes.

The footer lists the pages that have text. The banner sits at the bottom of the public pages until the visitor chooses. **Necessary only** keeps the cookies that sign someone in and that remember this choice. **Allow all** is the only choice that turns optional cookies on. The choice lasts 180 days, is not readable by a script on the page, and is sent with ordinary visits.

Nothing optional is loaded today. `CookieConsent::allowsOptional()` is the check a later script should use. Paying (Stripe, Tebex) is not optional and does not wait for this choice.

A page that is only a script is not published. A setting that is not the expected shape is treated as empty.

Viewing the site as a customer does not save a choice. That view cannot change anything.

## Not built

- **A tick at registration** that someone accepted the terms, and a history of each version.
- **A switch for each kind of cookie** beyond necessary and all.
- **Loading a third-party script.** The choice is stored. No analytics script is on the site.
- **Other languages.** The screens use translation keys. English is the only language shipped.
- **A policy written for you.** The pages start empty. The site does not invent legal text.

## Testing

`tests/Feature/Legal/LegalPagesTest.php`: an empty page is 404, a script and a `javascript:` link are removed, a page with no text is left out of the footer data and the sitemap, and publishing rebuilds the sitemap. An editor cannot open the form. Someone who can look at system settings but not change them cannot save. A guest can save a choice; a nonsense choice is refused; viewing as a customer does not set the cookie. Optional cookies are allowed only after **Allow all**.
