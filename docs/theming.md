# Theming and rebranding

How to make the site look like yours and say your name. There are two jobs: the **theme** (colours, fonts, shape), which lives in a few files, and the **brand** (the name, the logo, the words, the links), which is spread over more places than one would like. The colours were chosen once and written down in several of them; this page lists every place.

> **Status:** written from the code as it is. The theme tokens, the files that copy a colour, the logo script and the settings below were each read in the source; nothing here has been tried by changing the palette of a live site. The places that are **not** a setting are marked, because those are the ones a rebrand misses.

## What is a setting, what is a file

| What | Where | Needs |
|---|---|---|
| The name | `APP_NAME` (and `VITE_APP_NAME`), the `SEO_*` settings | `.env`, and `npm run build` for the browser's copy |
| Colours, fonts, corner radius | `resources/css/app.css`, `vite.config.ts` | `npm run build` |
| Where a colour is written out again | a handful of files, listed in [Where a colour is written twice](#where-a-colour-is-written-twice) | by hand |
| The logo and every icon | `resources/brand/*.webp`, then `php scripts/build-brand-assets.php` | GD with WebP |
| The colour of emails, their logo and footer | `MAIL_BRAND_COLOR`, `MAIL_LOGO_URL`, `MAIL_FOOTER` | `.env` |
| The share image for social networks | `SEO_IMAGE` | `.env`, an image you make |
| The words | `lang/en/*.php`, and a few pages that are written out ([Words and links](#words-and-links)) | by hand |
| The legal pages and the security contact | the admin area, `SECURITY_CONTACT_URI`, `SECURITY_POLICY_URL` | the admin area, `.env` |

See your changes as you make them with `npm run dev` (Vite reloads the page). `npm run build` makes the files a live site serves; with server-side rendering, `npm run build:ssr` ([deployment.md](deployment.md#server-side-rendering-optional)).

## Colours

The palette is a set of **design tokens** at the top of `resources/css/app.css`, in two blocks: `:root` for the light theme and `.dark` for the dark one. A token is three raw numbers, hue, saturation and lightness, with no `hsl()` around them (`--primary: 245 78% 59%;`), so that a colour can be given an opacity (`hsl(var(--primary) / 0.5)`). Tailwind's colour utilities (`bg-primary`, `text-muted-foreground`, `border-border`) are mapped onto them in the `@theme inline` block above, so changing a token changes every page and the admin area. (Emails do not read the tokens: they have their own colour, below.)

MetaForger's palette is a cool paper or deep navy field, one indigo and one gold. **To rebrand, change these and their dark-mode twins:**

| Token | What it colours |
|---|---|
| `--primary` | The fill of buttons, checks and the active pill. White text sits on it (`--primary-foreground`). |
| `--primary-text` | Links and other small text in the brand colour. In dark mode it is a lighter version of the same hue so it still reads on cards. |
| `--highlight` | The second colour, the gold: fills, rules, badges. Not for text on white. |
| `--ring` | The focus outline. Keep it the same hue as `--primary`. |
| `--sidebar-primary`, `--sidebar-ring` | The same colour in the sidebar. |
| `--chart-1` to `--chart-5` | The series of the admin charts. `--chart-1` is the brand colour. |
| `--glow-primary`, `--glow-highlight` | How strong the soft wash behind a page is (`0` removes it). |

Everything else (`--background`, `--foreground`, `--card`, `--surface`, `--muted`, `--border`, `--destructive`, `--success`, `--warning`, `--info`) is the neutral scale and the status colours. They can stay, or be tinted to your hue, but keep the pairs together (a `--card` and the `--card-foreground` on it). `--radius` (`0.9rem`) sets how round corners are, and every other size is derived from it.

`bg-brand-gradient` is a gradient in CSS (`app.css`, near the other utilities) that ends in a violet (`hsl(264 70% 52%)`) written out, not a token.

**Light and dark.** Both token blocks are used: the page gets the class `dark` on `<html>` when a visitor chose dark or their system is dark. A visitor's choice is the `appearance` cookie (`light`, `dark` or `system`), and with none the site follows the system. There is no setting for a default theme. Change a colour in one block and not the other and one theme will look unfinished.

**Contrast is yours to check.** The comments in the token blocks give the ratios they were chosen for (white on the indigo is about 6.2 to 1; `--primary-text` on a dark card about 6.4 to 1). Text needs 4.5 to 1. `npm run a11y` runs axe over the public pages, but it fails only for critical problems and a colour contrast problem is not one, so it will not tell you; check the pairs yourself ([accessibility.md](accessibility.md)).

### Where a colour is written twice

A few places cannot read a token, so the colour was written out. They are rounded copies (`245 78% 59%` is `#5346e8`, `223 47% 97%` is `#f4f6fb`, the dark background `224 52% 6%` is `#070b16`). **Change them with the tokens**, or the browser's address bar, the first paint, the installed app and the error page will show the old brand:

| File | What it holds |
|---|---|
| `resources/views/app.blade.php` | The two `theme-color` meta tags (the colour of the browser's own bar on a phone), and the page background before the stylesheet arrives (light and dark). |
| `app/Http/Controllers/WebManifestController.php` | `theme_color` and `background_color` of the installable app (`/site.webmanifest`). |
| `resources/views/errors/minimal.blade.php` | The standalone error page, which cannot load the site's CSS: its own `--canvas`, `--primary`, the gold and their dark versions. |
| `resources/js/layouts/auth/AuthSplitLayout.vue` | The dark panel beside the sign-in form (`bg-[#070b16]`). |
| `resources/js/components/AppearanceTabs.vue` | The small light, dark and system previews in the theme chooser. |
| `resources/js/lib/stripeAppearance.ts` | Stripe's payment form reads `--primary`, `--card`, `--foreground` and `--destructive` from the page, and uses these hex colours only if it cannot. It also names the font. |
| `config/mail.php` and `app/Support/Mail/MailBrand.php` | `#4f46e5`, the colour of emails. Not the site's indigo: a slightly different one, and a setting of its own (below). |

`git grep -n -i "5346e8\|f4f6fb\|070b16\|4f46e5\|f5b029"` finds them again after you change the palette.

## Fonts

Headings use **Outfit** and the rest **Source Sans 3**. They are listed in `vite.config.ts` (`bunny('Outfit', { weights: [500, 600, 700] })`) and **downloaded from Bunny Fonts when the site is built**, then served from your own address, so no visitor's browser talks to a font host and the Content Security Policy keeps `font-src 'self'` ([security-headers.md](security-headers.md)). The build needs to reach Bunny the first time.

To change a font, change it in three places and rebuild:

1. the `fonts` list in `vite.config.ts` (the name and the weights you use);
2. `--font-sans` and `--font-display` at the top of `resources/css/app.css`;
3. `fontFamily` in `resources/js/lib/stripeAppearance.ts`, so Stripe's form matches.

## Logo and icons

Two drawings are the source of every brand file: `resources/brand/logo-light.webp` (for light pages, dark lettering) and `logo-dark.webp` (for dark pages, light lettering), each 2000 by 667 pixels with a transparent background, the mark on the left and a gap before the lettering. Replace them and run:

```bash
php scripts/build-brand-assets.php
```

It writes the header logos, the mark, the favicons, the Apple and Android icons and the manifest's icons into `public/` (nothing in `public/` is edited by hand). It needs PHP's GD extension with WebP support. [resources/brand/README.md](../resources/brand/README.md) has the full list of files and where each is used. `tests/Feature/BrandingTest.php` then holds your drawings to what the site needs: every file there with the size it is used at, transparent corners (a solid background shows as a box on the other theme), dark lettering on the light logo and light lettering on the dark one.

The **name** is not part of the images: it is text, `APP_NAME`.

**The share image** (what Facebook, X and Slack show when your link is pasted) is not made for you. Make one 1200 by 630 and set `SEO_IMAGE` to its address. Without it pages carry no image and the card is the small one.

## Words and links

| What | Where |
|---|---|
| The site's name | `APP_NAME` (names in emails, the footer, the manifest and the title) and `VITE_APP_NAME` (the title the browser keeps). `.env.example` sets the second to `${APP_NAME}`, but the browser's copy is baked into the build: **run `npm run build` after changing it.** Renaming a live site **signs everybody out**, because the session cookie and the cache and Redis prefixes are made from the name ([configuration.md](configuration.md)): set `SESSION_COOKIE`, `CACHE_PREFIX` and `REDIS_PREFIX` first if you want to keep them. |
| Titles, descriptions, social cards | `SEO_SITE_NAME`, `SEO_DESCRIPTION`, `SEO_HOME_TITLE`, `SEO_HOME_DESCRIPTION`, `SEO_IMAGE`, `SEO_TWITTER_HANDLE` ([configuration.md](configuration.md#search-engines-and-social-cards)). The defaults describe MetaForger. |
| The footer's tagline and copyright | `lang/en/ui.php`: `footer.tagline`, `footer.rights`. |
| The footer's links, and the header's | `resources/js/components/AppFooter.vue` (the columns; the **GitHub** link points at MetaForger's repository) and `AppHeader.vue` (`baseMainNavItems`). A link's words are keys in `lang/en/ui.php`. |
| The home page | `resources/js/pages/Welcome.vue` is MetaForger's own pitch, written out in English, with two links to its repository. Replace its content. |
| The pricing page | Not MetaForger's: it shows the plans in **Billing → Plans** ([payments.md](payments.md#4-plans-only-if-you-sell-subscriptions)). |
| The quote beside the sign-in form | One of Laravel's. It is the `quote` prop in `HandleInertiaRequests` and `AuthSplitLayout.vue`; remove or replace it. |
| Emails | `MAIL_BRAND_COLOR`, `MAIL_LOGO_URL`, `MAIL_FOOTER`, `MAIL_FROM_NAME` ([email.md](email.md#setting-it-up)). |
| The legal pages | **Admin → Legal pages**. `php artisan db:seed --class=LegalPagesSeeder` fills the empty ones with starter text that has bracketed items to fill in ([legal-pages.md](legal-pages.md)). |
| **Where vulnerabilities are reported** | `SECURITY_CONTACT_URI` and `SECURITY_POLICY_URL`. **The defaults are MetaForger's own project pages**, and your site publishes them at `/.well-known/security.txt`. Set your own. |
| `SECURITY.md`, `README.md`, `CONTRIBUTING.md`, `CHANGELOG.md` | The repository's own pages; yours to rewrite. |
| `composer.json`, `package.json` | `name` (`laravel/vue-starter-kit`), `description` and `homepage`. |

The Artisan commands are named `metaforger:*` (`metaforger:install`, `metaforger:queue` and four more). Renaming them means changing each command's `$signature` and everything that calls them: the `dev` scripts in `composer.json`, `.github/workflows/tests.yml`, `scripts/check-install.mjs`, the docs, and your own deploy scripts and Supervisor program. It is optional.

## The licence

MetaForger is MIT licensed (`LICENSE.md`), which lets you rebrand, change and sell what you build on it. The one condition is that **the copyright notice and the licence text stay** in the copies or substantial portions you give to others. Keep `LICENSE.md` as it is and add your own line under it if you like. The libraries MetaForger uses have their own licences, listed in `THIRD-PARTY-NOTICES.md` ([licences.md](licences.md)); `npm run licences:check` fails if one you add is not allowed by the policy. This is not legal advice.

## Two tests that are MetaForger's own

`tests/Feature/BrandNameTest.php` guards the project's own rename: it fails if the old name appears anywhere and if a command is not called `metaforger:*`. In a fork under another name, delete it or change it to your name. `BrandingTest` is useful to keep: it holds your logo files to what the site needs.

## A rebrand, in order

1. `APP_NAME`, `VITE_APP_NAME`, `APP_URL`, the `SEO_*` settings, `MAIL_*`.
2. The tokens in `resources/css/app.css`, both blocks; then the hex copies ([above](#where-a-colour-is-written-twice)).
3. The fonts, if you change them.
4. The two logo drawings, then `php scripts/build-brand-assets.php`, then `php artisan test --filter=BrandingTest`.
5. `lang/en/ui.php` footer text, the footer and header links, `Welcome.vue`.
6. The legal pages, `SECURITY_CONTACT_URI` and `SECURITY_POLICY_URL`.
7. `npm run build`, and look at the home page, a sign-in page, the shop, a 404, the admin area, in light and dark, on a phone and a wide screen, and an email (`MAIL_MAILER=log`).
8. `git grep -il "metaforger\|metagrenade" -- . ':!CHANGELOG.md' ':!composer.lock' ':!package-lock.json' ':!THIRD-PARTY-NOTICES.md'` lists the files that still carry the old name.
