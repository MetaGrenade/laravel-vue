# Adding a language

How to make the site speak another language: what to copy, what to switch on, how the site decides which language a person sees, and what a new language does **not** reach. [i18n.md](i18n.md) explains how the pieces work inside; this page is the steps, and the things that surprise people.

> **Status:** MetaForger 1.0 ships English only. This guide was checked by doing it: a French copy of `lang/` was added, switched on, and requested in every way the site chooses a language (a visitor's browser, a member's setting, the `locale` cookie, a new account). The results are in [Who sees which language](#who-sees-which-language). No language other than English is shipped or translated for you; the first extra languages are planned for 1.1.

## What a language covers

| Covered by `lang/<code>` | Not covered |
|---|---|
| The header, the footer, the menus, the cart sheet, the notification bell | The admin area (it is English) |
| The sign-in and password pages | The body of the settings pages (labels, buttons, help text) |
| The frame of the settings pages (title, description, navigation) | The command palette, and the notices a page flashes after an action ("Saved.") |
| The error pages | The messages of the API, and the messages that tell whoever runs the site to change a setting |
| What a member is told when a form is refused (Laravel's own messages and the shop's 71 sentences) | Anything people write: posts, threads, tickets, product names, blog articles |
| The 21 notification emails, and the notices and browser alerts made from them | The Discord messages |
| The words around the legal pages: the footer link, "Updated :date", the cookie banner (`legal.php`) | The legal pages themselves: each site writes its own, and a page has one text |

The exact list, and how it grows, is in [What is translated today](i18n.md#what-is-translated-today). What is "still literal English in 1.0" stays English whatever you add.

## Steps

### 1. Copy the English files

```bash
cp -r lang/en lang/fr
```

Translate the **values** in each file of `lang/fr/`. Keep the keys exactly as they are, and keep every `:placeholder` (`:name`, `:count`, `:year`) in the sentence: it is replaced with the real value, and a sentence that loses it shows `:name` to the reader. Each key is one whole sentence on purpose; do not split one or join two, because another language puts the name or the number somewhere else. A count is written `one form|:count forms`, and the same text serves the server and the browser ([i18n.md](i18n.md#form-messages)).

| File | What it is | Reaches the browser |
|---|---|---|
| `ui.php` | The shared frame: header, footer, menus, the cart sheet | Every page |
| `members.php`, `legal.php` | Member pages (directory, profile, messages), and the words around the legal pages and the cookie banner | Every page |
| `auth.php` | The sign-in and password pages | The sign-in pages only |
| `settings.php` | The frame of the settings pages | The settings pages only |
| `errors.php` | The error pages | The error pages |
| `forms.php`, `validation.php`, `passwords.php` | What a refused form says | Never: the server writes them |
| `mail.php` | The emails, and the notices and alerts made from them | Never |

`validation.php` and `passwords.php` in `lang/en` are copies of Laravel's own files. Laravel's translations for many languages are in the `laravel-lang/lang` package, which is a good place to take them from.

### 2. Turn it on

In `.env`:

```
APP_SUPPORTED_LOCALES=en,fr
```

`APP_SUPPORTED_LOCALES` is the list of languages the site offers. It does not need `APP_LOCALE` changed: that is the language the site falls back on, **the site's own language** (default `en`), used when nothing asks for another. Set it to `fr` only if French is the language of your site. Clear the config cache (`php artisan config:clear`) if you cache it. A code that is not in the list is never used, whatever a browser or a profile asks for.

### 3. Check it is complete

```bash
php artisan lang:check
```

It compares every folder in `lang/` with `lang/en`, **whether or not the language is switched on**, and fails for each key that is missing:

```
[fr] missing ui.nav.skip_to_content
1 translation key(s) are missing.
```

It also lists keys a language has that English does not. Our own CI runs this command, so a language that is not complete **fails CI**; if you would rather ship a language in parts, take `lang:check` out of your workflow or keep the unfinished language in a branch.

A missing key does not break a page. The browser is sent the English text under the language's, and the server falls back to `APP_FALLBACK_LOCALE` (`en`), so a half-translated language reads as English where it is missing. That is a safety net for a missed key, not a way to ship less.

### 4. Laravel's own sentences in emails

The framework's "Verify your email address" and "Reset your password" mails, the closing "Regards," and the footer's "All rights reserved." are looked up by their English text, in `lang/fr.json`, not in `lang/fr/`:

```json
{ "All rights reserved.": "Tous droits réservés." }
```

`lang:check` does not read the `.json` files, so nothing fails when they are missing: those sentences are simply English in your language's emails. The `laravel-lang/lang` package has them for most languages.

### 5. Look at it

Open the site with the browser set to the new language, or sign in and choose it in **Settings → Profile → Locale**. Check the header, a sign-in page, an error page (`/no-such-page`), a form that is refused, and an email (set `MAIL_MAILER=log`, trigger one, read `storage/logs/laravel.log`).

## Who sees which language

`SetLocale` chooses a language for each request, in this order, and the first one the site offers wins:

1. The signed-in member's **Locale** (Settings → Profile).
2. A cookie called `locale`.
3. The browser's language (`Accept-Language`), taking the visitor's own order of preference.
4. `APP_LOCALE`.

A value such as `fr_CA` or `FR-ca` is reduced to `fr`, and counts only if `fr` is in `APP_SUPPORTED_LOCALES`. What the site actually does, as tried with a French added next to English:

| Who asks | Language shown |
|---|---|
| A visitor whose browser asks for `fr-CA, fr, en` | French |
| A visitor whose browser asks for `de-DE` only | English (the site has no German) |
| A visitor whose browser asks for `de, fr` | French (German is skipped) |
| A visitor whose browser asks for `en` first, `fr` second | English |
| A visitor whose browser sends nothing | English |
| A member whose Locale is `fr_CA`, with an English browser | French: the member's setting wins over the browser |
| A member whose Locale is `en_US`, with a French browser | **English**: the member's setting wins over the browser |
| A member whose Locale is `de` (not offered), with a French browser | French (the setting is skipped, the browser is asked) |
| A visitor with an encrypted `locale` cookie (the site never sets one, see below) | The cookie's language |
| A visitor with a `locale` cookie typed in by hand | Ignored (see below) |

### Three things to know

- **A new member is English until they choose otherwise.** The `users.locale` column is filled with `config('app.locale')` as the table is made, so every account has `en` stored from the start, and a stored `en` beats the browser. A visitor who browses in French and then registers is shown English from the next page on, until they pick French in Settings → Profile → Locale. Tried: registering with a French browser stored `en`, and the next page was English. On a site that is French first, install with `APP_LOCALE=fr` so the column starts as `fr`. To move members who are already there you have to update the column yourself; there is no button for it.
- **There is no language switch for visitors in 1.0.** `SetLocale` reads a cookie called `locale`, but nothing in the site writes one, and Laravel encrypts cookies, so a `locale=fr` typed in by hand is not understood. A visitor who is not signed in gets the language of their browser, and cannot change it from the site. A switch is not built. It is a small piece of code (a route that stores the choice in that cookie, and a menu that calls it), and until you add it a visitor's language is their browser's.
- **The Locale list offers every language in the world**, not only the ones the site has: it is a formatting preference too (dates are written in the member's own locale). A member who chooses `de` on a site with no German sees English text and German dates. Only a language in `APP_SUPPORTED_LOCALES` changes the words.

An email or a notice is written in the language of the person it is for, chosen when it is sent, not in the language of the person who caused it ([i18n.md](i18n.md#emails)). So it follows the same stored Locale: a member whose Locale is `en` gets English emails from a French site. A guest who orders gets the site's language (`APP_LOCALE`), because an order does not store the language the buyer browsed in.

## What a language does not change

- **The shop's providers.** The shop does not tell Stripe which language to use, and Tebex's pages are in the language Tebex chooses.
- **Search engines.** `SEO_LOCALE` (default `en_US`) is one value for the whole site: set it to the site's own language. There is no `hreflang` and there are no addresses per language, because a page is one page whatever language it is shown in. Both are planned for 1.1.
- **Right-to-left languages** (Arabic, Hebrew). The page has a `lang` but no `dir`, and the layouts are not checked for right-to-left. New code uses logical spacing (`ms-`, `pe-`) so it can be done, but it has not been.
- **Numbers and money in the browser.** Some pages format with the browser's own language and the cart sheet always writes `$1,234.50`. Emails write an amount as a decimal and the currency's code (`12.50 USD`) in every language.
- **A language with more than two forms of a plural.** On the server Laravel picks the form by the language's rules. In the browser a message with `one|other` picks the first form for `one` and the second for everything else, which is wrong for languages such as Russian or Polish. Write those messages with explicit ranges instead (`{1} :count file|[2,4] :count files|[5,*] :count files`), which are read the same on both sides.
- **The seeded and starter content.** The legal starter text ([legal-pages.md](legal-pages.md)) and the demo data are English; the legal pages are text you write for your own site.

## Keeping a language up to date

A new key in `lang/en` fails `lang:check` for every other language until it is translated, which is the point. After you pull a new version of MetaForger:

1. Run `php artisan lang:check` and translate what it lists.
2. If Laravel was upgraded, `ServerTranslationKeysTest` may say the copies of `validation.php` or `passwords.php` in `lang/en` lack a key; copy it in, then add it to your language too.
3. The new keys of `config/notification-preferences.php` (the labels on the Notifications page and the names of a digest's areas) are English in every language; they are not in `lang/`.

When you add a screen of your own, put its sentences in `lang/en` first ([Adding strings](i18n.md#adding-strings)) and translate them after; `TranslationKeysTest` and `ServerTranslationKeysTest` fail for a key that is used and not defined.
