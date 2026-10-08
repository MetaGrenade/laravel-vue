# Changelog

All notable changes to MetaForge are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The path to 1.0.0 is laid out in [docs/ROADMAP-v1.0.0.md](docs/ROADMAP-v1.0.0.md).

## [Unreleased]

### Added

- A `docs/ROADMAP-v1.0.0.md` release plan covering features, security, performance, payments (Stripe and Tebex), search and the milestones to 1.0.0.
- Authorised download route for support-ticket attachments (web and API) with a policy, plus a `support:attachments:privatize` command.
- CI jobs that run the test suite on MySQL 8 and PostgreSQL 16 in addition to SQLite, with a guard test that fails if a job runs on the wrong database.
- `SECURITY.md`, `CONTRIBUTING.md`, `.well-known/security.txt`, issue and pull-request templates, `CODEOWNERS` and Dependabot configuration.
- `compose.yaml` with the backing services for local development (MySQL, PostgreSQL, Redis, Meilisearch and Mailpit).
- Internationalisation infrastructure (English only for now): `config/i18n.php`, the `SetLocale` middleware, `useI18n()`, shared translations as Inertia props and `php artisan lang:check` (see `docs/i18n.md`).
- Static analysis with Larastan (level 5, with a baseline that shrinks as relations are typed), a gitleaks secrets scan, a weekly dependency-advisory audit and a scheduled workflow that repeats the test suite in random order.
- **Checkout and orders (M1, first slice).** Guests and signed-in customers can check out a cart with Stripe: an order is placed with its stock held, payment happens on hosted Stripe Checkout, and a webhook marks it paid and emails a receipt. Orders have a lifecycle (`pending`, `processing`, `completed`, `cancelled`) separate from payment state, unpaid orders expire and release their stock, and every stock change is recorded in an inventory ledger. A cart never has two payable checkouts: starting again closes the earlier Stripe session and confirms it before a replacement is created, and paying removes only the ordered lines from the cart. See [docs/commerce.md](docs/commerce.md).
- A provider-neutral payment layer (`App\Payments`): a `PaymentProvider` contract, `PaymentManager`, `StripeProvider`, and a store-level provider setting in the ACP (System settings). Tebex plugs in behind the same contract in M2.
- Idempotent webhook handling shared by all providers: each delivery is stored under `(provider, external_id)`, redeliveries are acknowledged without reprocessing, and failures are recorded and retried by the provider.
- Cart quantity updates and removal, a checkout page, an order confirmation page (signed link for guests) and an owner-scoped order history.
- `Money` value object for exact arithmetic in minor units, including zero-decimal currencies, and `COMMERCE_*` configuration (`config/commerce.php`).
- Orders and payments belong to an owner (`owner_type`/`owner_id`) and are authorised through a policy, so teams can own orders in 1.1 without a rewrite.
- Factories for products, variants, prices, inventory, orders and payments.
- **Addresses, shipping and tax at checkout (M1, second slice).** Checkout collects a shipping address (and a billing address when it differs, or for digital goods when tax depends on location), offers the shipping methods that apply and shows shipping and tax as the shopper types. A signed-in customer has an address book (Settings → Addresses) and can pick or save addresses. Orders keep their own copy of both addresses. See [docs/commerce.md](docs/commerce.md).
- Shipping zones and rates (by country, with a rest-of-world zone, minimum and maximum order values such as free shipping over a threshold) and a tax-rate table (country and region, several rates adding together, rate applying to shipping or not, products can be untaxed). With no zones configured, orders ship anywhere for free as before.
- `OrderPricer`, one pricing engine for the live quote and the order that is placed, working in exact minor units with the tax shared between lines by largest remainder (`Money::percent()` and `Money::allocate()`). Stripe is sent shipping and each tax as their own lines, and the shipping address is attached to the payment.
- Products can be marked as not needing shipping (digital goods) and as not taxable; the ACP product form sets both.
- Example shipping zones, rates and tax rates in the demo seeder.
- **Admin screens for shipping and tax.** Commerce → Shipping manages zones (pick countries one by one, add all EU countries, or cover everywhere else) and their rates (price, optional minimum and maximum order value, active or not); Commerce → Tax rates manages the tax table. The screens warn when shipping isn't set up or a zone has no active rates, and when you are about to delete your last active zone. Each action has its own permission (`commerce.acp.view`, `create`, `edit`, `delete`).
- **Order management and refunds (M1).** Commerce → Orders lists and searches orders (by status, payment state, number, name or email) and opens each one to show its items, totals, customer, addresses, payments and history. Staff can mark an order fulfilled with optional tracking (the customer is emailed), cancel an unpaid order, check a payment with the provider, and leave notes. Every change is written to an append-only order history with who did it.
- **Refunds.** Full or partial refunds sent back through Stripe from the order page, with a reason, a note, an option to return the items to stock and an option to email the customer. A refund is recorded before the provider is asked and is idempotent, so a double click or a lost reply cannot refund twice; a refund Stripe has not confirmed stays pending until it is checked, and a refund that fails frees its balance. Refunds made in the Stripe dashboard are picked up through the `refund.*` and `charge.refunded` webhooks, and a refund made outside the shop can be recorded by hand. Orders gain the payment states `partially_refunded` and `refunded`; a full refund of an unshipped order cancels it. Refunding has its own permission, `commerce.acp.refund`. See [docs/commerce.md](docs/commerce.md#refunds).
- **Catalogue management (M1).** Commerce → Products lists and searches products (by name, address or SKU) and opens each to edit everything in one place: details, brand, categories and tags; the price; options and variants; and stock. A checklist on the page says what is still missing before a product can be bought. Options can be renamed (the variants made from them follow) and a variant is made for every combination with one click. Prices follow one rule (a single active price in the shop's currency), so what a shopper pays is never ambiguous. Products and variants that have been ordered are archived or switched off rather than deleted, so order history and the stock ledger stay intact; everything else can be deleted and takes its prices, stock and cart lines with it.
- **Stock adjustments.** Staff can set a counted figure or add and remove stock with a note; each change is written to the movement ledger (reason `adjustment`) with who made it, and tracked stock that is running out is listed on the Commerce overview (`COMMERCE_LOW_STOCK_THRESHOLD`).
- Brands, categories and tags can be created, edited and deleted (Commerce → Brands and tags); until now they could only be seeded.
- Variants have an on/off switch (`is_active`): a variant that is off is not shown, cannot be added to a cart and stops a cart that holds it at checkout.
- Customers see refunds and tracking details on their order, and are emailed when an order ships and when a refund goes through.
- Web manifest generated from configuration; home page SEO copy configurable through `seo.home.*` (`SEO_HOME_TITLE`, `SEO_HOME_DESCRIPTION`).

### Changed

- The Commerce overview in the ACP no longer holds the old quick-create forms (they were replaced by the product, variant, price and stock screens), formats money in the shop's currency rather than always dollars, and counts only stock that can be sold in its on-hand figure.
- The revenue figure on the ACP Commerce page now counts only paid orders, less what was refunded (it used to add up every order, including unpaid and cancelled ones).
- Support attachments are stored on a private disk (`SUPPORT_ATTACHMENT_DISK`, default `local`) instead of the public disk. A migration moves existing files.
- All text filters and search use case-insensitive `whereLike`, so they behave the same on MySQL, PostgreSQL and SQLite.
- The brand name now comes from one place (`APP_NAME`); the logo, web manifest and home page no longer hard-code it.
- Default `APP_NAME` in `.env.example` is `MetaForge`.
- Only prices in the store currency (`COMMERCE_CURRENCY`) can be added to a cart, and checkout prices every line again from the catalogue instead of trusting the cart.
- The header cart no longer shows an estimated 7% tax and a flat shipping charge that were not real; it shows the subtotal and links to the cart.
- `billing_webhook_calls` is keyed by `(provider, external_id)` and records attempts and errors; a migration backfills existing rows. The stored `stripe_id` is kept for the admin screens.
- The Stripe webhook controller verifies signatures through a shared `StripeSignatureVerifier`; behaviour is unchanged.
- Products are shipped by default, so checkout now asks for a shipping address. Shops with no shipping zones configured are unaffected: orders still ship anywhere at no charge.
- Validation messages for addresses use plain field names ("The postal code field is required").

### Removed

- The unfinished `mysql_fulltext` search driver (it referenced FULLTEXT indexes that were never created). Meilisearch support is planned for 1.0.

### Fixed

- **Security:** support-ticket attachments were reachable by anyone with the URL.
- `SupportTicketMessage::ticket()` and `SupportTicketAudit::ticket()` looked for a nonexistent `ticket_id` column and always returned `null`.
- **PostgreSQL:** the blog `scheduled` status was rejected by a CHECK constraint that the original migration never widened. A new migration (`2026_10_08_000100_allow_scheduled_blog_status_on_postgresql`) fixes both fresh and existing PostgreSQL databases; existing installations only need to run `php artisan migrate`.
- Rolling back the blog status migration now turns `scheduled` posts into drafts first, so the rollback no longer fails once any post has used that status.
- Moving legacy support attachments to the private disk now keeps a row on its original disk when the original file cannot be deleted, so the move is retried and reported instead of looking complete while a public copy remains.
- Carts that had become orders were still returned as the shopper's cart; only open carts are used now.
- Cart line totals are computed in integer minor units instead of floating point.
- Dialogs that add something (an address in the address book) no longer open pre-filled with the previously saved values: Inertia's `form.reset()` returns to the last *submitted* values, not blanks.

## Before the changelog

Earlier work is not itemised here; it is summarised by its pull requests:

- #246 Design refinements: backgrounds, animations and performance.
- #245 Modernised design system, layouts and pages with light and dark themes.
- #244 Upgrade to Laravel 13 and a modern front-end stack, with security, SEO and bug fixes.
- #243 Polls and surveys.
- #241 Comment spam protection and rate limiting.

[Unreleased]: https://github.com/MetaGrenade/laravel-vue/commits/main
