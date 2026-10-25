# Email design

Every email the site sends (an order confirmation, a failed payment, an API key notice, the "verify your email" and "reset your password" mails Laravel sends itself) comes in **one layout**: the name and colour of the site, a logo or a badge, a footer, a plain-text version, and a dark one for mail apps that are dark.

> **Status:** built: the layout, the theme, the settings, the plain-text version, dark mode, tests that render real notifications, and a daily digest of community email ([email-digests.md](email-digests.md)). **Not built:** see [below](#not-built).

## Setting it up

Nothing is needed for a working layout: it takes the site's name (`APP_NAME`) and address (`APP_URL`) and the colour of the site. To make it yours, in `.env`:

| Setting | What it is | Default |
|---|---|---|
| `APP_NAME` | The name in the header, the footer and the page title. | |
| `APP_URL` | Where the header links to. | |
| `MAIL_BRAND_COLOR` | The colour of buttons, the badge and links, as a hex colour (`#4f46e5` or `#45e`). Anything else is ignored and the default is used. | `#4f46e5`, the site's own |
| `MAIL_LOGO_URL` | The address of a logo image (`https://` or `http://`). Without one the header shows a badge with the first letter of the name, and the name. | none |
| `MAIL_FOOTER` | One line under every email, such as a company name and address. | none |

A logo needs to be an image on a public address, as mail apps do not show an image that is inside the email unless it is asked for. About 220 × 48 pixels or twice that is right; it is shown at most that size.

### Readable whatever the colour

You can choose any colour. The layout picks what goes on it so that it can still be read (WCAG's 4.5 to 1):

- the words on a button are **white or black**, whichever reads better on the colour;
- links in the text are the colour itself when it reads on white, **dark** when it does not (a pale yellow);
- links in a dark mail app are the colour **lightened** until they read on the dark page.

## What is in it

| Where | What |
|---|---|
| `config/mail.php` | `markdown.theme` (`brand`), `markdown.paths` (so the layout below is used) and the `brand` settings above. |
| `resources/views/mail/brand.blade.php` | The theme: the CSS of every email. It is a Blade view, not a `.css` file, so the colour can come from the settings. |
| `resources/views/vendor/mail/html/` | The layout (`layout`), the header (`header`) and the message with its footer (`message`). The other parts (button, panel, table) are Laravel's own. |
| `resources/views/vendor/mail/text/message.blade.php` | The plain-text version's footer. |
| `app/Support/Mail/MailBrand.php` | What the views take from the settings, each checked to be what it should be (a colour is a colour, a logo is an address on the web). |

**Dark mode** is in the layout's `<style>`, not the theme, because the renderer inlines the theme into the HTML and leaves media queries out. Apple Mail and the iOS and macOS apps follow it; Gmail's apps and Outlook ignore it and show the light email.

**The header** is a table with the badge and the name, each a link to the site. A link cannot hold a table (the renderer takes the table out of it), so do not wrap them in one.

## Writing a notification

Use `MailMessage` as the notifications here do: `greeting()`, `line()`, `action()`. Do **not** call `->view()`, `->markdown()` or `->theme()`: that is a layout of its own, and a test fails when a notification does.

- **Text somebody else typed** (a key's name, a ticket subject) goes in as `ApiKeyCreated::literal($text)` or is otherwise made safe: the lines are read as Markdown, so a `[link](https://...)` in a name would be a link in the email.
- **Secrets are never put in an email.** Say where to find them, and link there ([an order's licence keys](commerce.md) are the example).
- **A link that has to work for somebody who cannot sign in** (a guest's order) is a signed URL.

A `Mailable` is not used anywhere. If one is added, put it through the same layout (`->markdown('...')` with the `mail` components) and add it to `tests/Feature/Mail/BrandedMailTest.php`, which now fails if `app/Mail` exists.

## Looking at one

Set `MAIL_MAILER=log` and the next email is written to `storage/logs/laravel.log`. To see one without sending it, in `php artisan tinker`:

```php
$user = App\Models\User::first();
file_put_contents('mail.html', (new App\Notifications\SubscriptionCancelledForNonPayment('Pro'))->toMail($user)->render());
```

and open `mail.html`. Mail apps differ more than browsers do: check one that is dark (set the browser's colour scheme) and a phone.

## Testing

`tests/Feature/Mail/BrandedMailTest.php` renders messages (and the real notifications, and Laravel's own) through the layout and checks the header, the colour of the button, the badge and links, the logo, the footer, the plain-text version, the dark styles, that markup in the name or footer is shown as text and not run, that a colour or logo that is not one cannot get into the page, and that no notification picks a layout of its own. `tests/Feature/Mail/MailBrandTest.php` covers what the settings may be and the contrast.

## Not built

- **A different footer for each email**, such as a link to the recipient's notification settings or "you are receiving this because...". The footer is the same for all; some emails go to people with no account (a guest's order), and some ignore the settings (security notices).
- **Setting the logo and colour in the admin area.** They are in `.env`.
- **Other languages.** The layout's own words ("All rights reserved.") go through Laravel's translations; the notifications' words are English, like the rest of the shop ([i18n.md](i18n.md)).
- **A page that previews every email.**
