# MetaForge v1.0.0 Release Plan

Status: scope decided (see section 11). Reviewed against `main` at commit `3ff2ddc` (Laravel 13, Inertia 3, Vue 3, Tailwind 4).

**Scope decisions applied in this version:** no teams in 1.0 (schema made team-ready for 1.1); Tebex Headless API in 1.0 with the Checkout API behind a feature flag; store-level payment provider; MySQL 8 and PostgreSQL 16 in CI with SQLite for local and tests; English-only with an extraction-ready i18n structure; Meilisearch search in 1.0; MIT licence; passkeys in 1.1.

How to read this: every finding is marked **Verified** (I read the code or ran a check), **Inferred** (a conclusion from what exists, or from what I could not find) or **To verify** (needs checking before acting). Effort figures are rough single-developer days, intended for ordering and sizing, not for commitments.

---

## 1. Summary

MetaForge already has a strong base: authentication with two-factor, OAuth and session management; Stripe subscriptions; a blog, forum, support desk and polls; a 166-route admin panel with role-based access; global search; SEO and server-side rendering; a hardened security layer; GDPR export and erasure; a versioned API; and 338 passing tests.

It is not yet a v1.0.0 for the three audiences you named. The blockers are:

| # | Blocker | Why it blocks 1.0 |
|---|---------|-------------------|
| 1 | **The shop cannot sell anything.** There is a catalogue, a cart and an order list, but no checkout, no order creation and no payment path. The cart's "Checkout" button has no handler. | An eCommerce boilerplate that cannot complete an order is not 1.0. |
| 2 | **Payments are hard-wired to Stripe/Cashier.** | Tebex (and any future provider) needs a provider abstraction first. |
| 3 | **Support attachments are stored on the `public` disk.** Private support-ticket files are served by URL with no authorisation check. | Privacy defect in a shipped feature. |
| 4 | **Plans do not gate anything.** Subscribing has no effect on what a user can do. | A SaaS boilerplate needs entitlements. |
| 5 | **No coupons, tax, shipping, legal pages or cookie consent.** (Teams are deliberately deferred to 1.1.) | Expected by buyers of each of the three kinds of site. |
| 6 | **Tests only run on SQLite;** production targets MySQL 8 and PostgreSQL 16. | Whole classes of bugs (full-text search, JSON, locking, case-sensitive `LIKE` on PostgreSQL) are untested. |
| 7 | **No release hygiene:** no changelog, security policy, contribution guide, Docker setup or upgrade guide; README still says "Laravel Vue Starter". | Required for a public 1.0. |

Plan: eight milestones (M0–M7) in section 9, roughly **70–100 developer-days** with the decided scope. Tebex lands in M2, immediately after the payment abstraction in M1.

---

## 2. What exists today

| Area | State | Evidence |
|------|-------|----------|
| Auth | Register, login, email verification, password reset, TOTP two-factor with recovery codes, OAuth, session management, banned-user handling | Verified (controllers, tests) |
| Authorisation | Spatie roles/permissions; `admin`/`editor`/`moderator`; per-section ACP permissions; 3 model policies | Verified |
| Billing (Stripe) | Cashier 16; plans in DB; subscribe, cancel, resume; payment methods; invoices (PDF via dompdf); webhook processing with stored calls | Verified |
| Shop | Products, variants, options, prices, brands, categories, tags, inventory items, cart | Verified (models, migrations) |
| Orders | `Order`/`OrderItem` models and a read-only "my orders" page. **Nothing creates an order.** | Verified |
| Blog | Rich text, categories, tags, scheduling, revisions, comments with reactions, reports, RSS, previews | Verified |
| Forum | Categories, boards, threads, posts, revisions, mentions, subscriptions, reports, read tracking, reputation and badges | Verified |
| Support | Tickets, teams, assignment rules, SLAs, canned replies, FAQ with feedback, ratings, audit trail | Verified |
| Polls | Polls, options, votes, admin UI, API | Verified |
| API | Versioned `/api/v1` (37 routes), Sanctum tokens, Swagger UI | Verified |
| Search | Global search across blog, forum, FAQs with analytics. Default driver is `database` (LIKE) | Verified |
| SEO | Meta, JSON-LD, sitemap, robots, working SSR | Verified |
| Security | Nonce CSP, header middleware, named rate limiters, HTML sanitising, JSON sessions, cache class allow-list, CI dependency audits | Verified |
| Privacy | Data export (queued), data erasure requests, notification preferences | Verified |
| Realtime | Pusher/Echo notifications and presence | Verified |
| Admin | Dashboard, users, ACL, content, moderation, billing, tokens, system settings, section toggles, diagnostics | Verified |
| CI | Lint, types, tests, build, dependency audit | Verified |
| Scheduler | Support SLA monitor, search stats aggregation and pruning | Verified |

---

## 3. Gap analysis by audience

### 3.1 SaaS

| Need | State |
|------|-------|
| Entitlements: plans that grant features and limits, enforced in code and UI | **Missing.** No gating helper, middleware or feature flags tied to plans. (Verified: no usage outside billing screens.) |
| Teams / organisations, invitations, per-team roles, per-team billing | **Missing, deferred to 1.1.** 1.0 ships single-user accounts but is built so teams can be added without rewriting billing or permissions (section 7.4). |
| Trials, coupons, proration, dunning | **Partial.** Cashier supports trials and proration; no UI, no coupon model, no dunning emails. |
| Usage-based billing | **Partial.** Cashier meter migrations exist; no meters or reporting wired up. |
| Tax | **Missing.** `tax_total` exists on orders but nothing computes it; Stripe Tax not enabled. |
| Customer-facing API keys | **Partial.** Tokens are managed by admins in the ACP; no self-service page in user settings. |
| Outbound webhooks for customers | **Missing.** |
| Admin impersonation, admin audit log | **Missing.** Only support tickets have an audit trail. |
| Onboarding flow, in-app changelog/status | **Missing.** |
| Transactional email design | **Partial.** 8 notifications use the default Laravel mail templates; no branded layout. |

### 3.2 eCommerce

| Need | State |
|------|-------|
| Checkout (address, shipping, tax, payment, confirmation) | **Missing.** |
| Order lifecycle: pending, paid, fulfilled, cancelled, refunded; order emails | **Missing.** Orders have a `status` column and nothing that changes it. |
| Inventory reservation and decrement | **Missing.** Inventory rows exist; nothing reserves or reduces stock. |
| Shipping zones and rates; address book | **Missing.** |
| Discounts: coupons, gift cards, sales | **Missing.** `discount_total` exists with no model behind it. |
| Admin catalogue management | **Partial.** Create-only: `storeProduct`, `storeBrand`, `storeVariant`, `storePrice`, `storeInventory`. No edit, delete or order management. |
| Product media | **Missing.** No image tables. |
| Reviews, wishlists | **Missing.** |
| Digital goods: downloads, licence keys | **Missing.** |
| Refunds and returns | **Missing.** |
| Abandoned-cart recovery | **Missing.** |

### 3.3 Large communities

Strong already. Remaining gaps, in priority order:

1. **Public member profiles and a member directory.** Users have bios and social links, but there is no public profile page.
2. **Moderation depth.** Reports exist; add warnings/infractions, ban history, and automod for the forum. The spam guard (`CommentGuard`) covers blog comments only.
3. **Thread features:** tags/prefixes, "solved" answers, ignore list, attachments and image uploads in posts.
4. **Direct messages and following.**
5. **Email digests** and notification batching.
6. **Forum RSS** (blog has it).
7. **Leaderboards / activity feed** (reputation data already exists).

---

## 4. Security review

### 4.1 Findings

| Sev | Finding | Status | Fix |
|-----|---------|--------|-----|
| **High** | Support-ticket attachments are written to the `public` disk (`SupportCenterController`: `$disk = 'public'`). Files are reachable without authentication or ticket authorisation. The stored MIME type is the client-supplied one. | Verified | Move to a private disk; serve through an authorised controller with `Content-Disposition: attachment`; validate by sniffed MIME and extension allow-list; cap size; optional AV hook. Add a migration to move existing files. |
| High (latent) | Blog covers also use the public disk; fine for public images, but there is no image re-encoding, so crafted files are served as-is. | Verified | Re-encode and strip metadata on upload; limit dimensions. |
| Medium | Two-factor is a custom TOTP implementation (`TwoFactorAuthenticator`). Secrets are stored encrypted. No recovery-code rotation prompt. | Verified | Review against RFC 6238 test vectors; add tests. **Passkeys/WebAuthn are deferred to 1.1.** |
| Medium | Sanctum tokens default to abilities `['*']` and no expiry. | Verified | Default to least-privilege abilities; set `SANCTUM_EXPIRATION`; add per-token rate limits. |
| Medium | No admin audit log. Role changes, bans, refunds and settings changes are not recorded. | Verified | Add an append-only `audit_logs` table and a model observer/trait; ACP viewer. |
| Medium | Webhook handling is Stripe-specific; no replay window or idempotency key on the stored calls. | Inferred | Generalise (section 7) with unique `(provider, external_id)` and a processed-at guard. |
| Medium | No cookie-consent mechanism or legal pages (Terms, Privacy Policy). | Verified | Add configurable legal pages and a consent banner that gates optional scripts. |
| Low | No `SECURITY.md` or `/.well-known/security.txt`. | Verified | Add both. |
| Low | CSP `style-src` includes `'unsafe-inline'`. | Verified | Acceptable for 1.0 (Tailwind/Vue inline styles); document, and tighten if feasible. |
| Low | Password rules are strong in production (min 12, mixed case, numbers, uncompromised) and weak elsewhere (min 8). | Verified | Fine; document. |
| Low | `Model::preventLazyLoading` only logs and only locally. | Verified | Keep; add a test-suite mode that fails on lazy loading. |
| Info | Positives: nonce CSP, `PreventRequestForgery`, sanitised rich text, named rate limiters, banned-user middleware, Stripe signature check, `.env` not tracked, dependency audits in CI. | Verified | Keep. |

### 4.2 Hardening checklist for 1.0

- [ ] Private storage and authorised downloads for all user uploads
- [ ] Upload pipeline: MIME sniffing, extension allow-list, size caps, image re-encode
- [ ] Admin audit log
- [ ] Token abilities default, expiry, rate limits
- [ ] Per-route authorisation review: every ACP route behind a named permission (currently 166 admin routes; **To verify** that none rely on role only)
- [ ] Policies for orders, tickets, products (only 3 policies exist today)
- [ ] Webhook idempotency and replay protection for all providers
- [ ] Brute-force protection on 2FA challenge and recovery codes (**To verify**)
- [ ] Security headers test in CI
- [ ] `SECURITY.md`, `security.txt`, dependency-update automation (Dependabot or Renovate)
- [ ] Secrets scanning in CI
- [ ] Optional: CAPTCHA provider integration for registration and contact forms
- Deferred to 1.1: passkeys (WebAuthn)

---

## 5. Performance review

### 5.1 Findings

| Impact | Finding | Status | Fix |
|--------|---------|--------|-----|
| High | **Default drivers are all database-backed:** `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` = `database`. Fine for development, a bottleneck at community scale. | Verified | Document and default production to Redis; ship a `docker-compose` with Redis; add Horizon as an option. |
| High | **Search:** default is `LIKE` queries. The optional `mysql_fulltext` driver issues `MATCH … AGAINST` but **no FULLTEXT index migration exists**, so enabling it on MySQL would error. `LIKE` is also case-sensitive on PostgreSQL, so the default driver would behave differently there. | Verified (code); PostgreSQL behaviour Inferred | **Decision: ship Meilisearch in 1.0** (section 8A). Remove the `mysql_fulltext` driver. Make the `database` fallback portable (case-insensitive on PostgreSQL). Test both databases in CI. |
| High | **Database portability:** raw SQL and engine-specific behaviour (`MATCH … AGAINST`, JSON operators, date formatting, `GROUP BY` strictness, case-insensitive collation) have only been exercised on SQLite and MySQL. | Inferred | One-off audit of every `DB::raw`/`whereRaw`/`selectRaw` call in M0; route differences through the `Sql` helper; the CI matrix (MySQL 8, PostgreSQL 16) is the safety net. |
| Medium | Very large controllers: `Admin/SupportController` 1,530 lines, `Admin/BlogController` 991, `BlogController` 673, `ForumController` 658. Hard to optimise or test. | Verified | Extract query/action classes while writing the M4 features; do not do a big-bang refactor. |
| Medium | No HTTP caching for guest traffic on blog/forum/home. | Inferred | Cache-Control + ETag for guest GETs; cache fragment queries (counts, trending). |
| Medium | Image handling: lists load full-size covers (I measured a 1080×1350 PNG shown at 388×159). | Verified | Generate responsive variants (WebP/AVIF) on upload; `srcset`; CDN. (Lazy loading is already done on the blog list.) |
| Medium | Queries: lazy-loading violations are logged locally only; no query-count regression tests. | Verified | Add `preventLazyLoading` in tests; add query-count assertions on top pages. |
| Low | Last-activity updates are already throttled to once a minute. | Verified | Keep. |
| Low | Front-end: client bundle is code-split; editor and charts are lazy chunks. No size budget in CI. | Verified | Add a bundle-size budget check. |
| Low | No Lighthouse/Web-Vitals budget. | Inferred | Add Lighthouse CI on home, blog, forum, shop. |

### 5.2 Targets for 1.0

- Guest page TTFB under 200 ms on a small VPS with Redis and OPcache
- Largest Contentful Paint under 2.5 s on 4G for home, blog post and product pages
- No N+1 queries on the top 20 pages (enforced in tests)
- Queue-backed email, exports and webhook processing

---

## 6. Quality, DevEx and release hygiene

| Item | State | Plan |
|------|-------|------|
| PHP tests | 84 files, 338 tests, **SQLite in-memory only** | CI matrix: **MySQL 8 and PostgreSQL 16** as the supported databases; SQLite stays for local development and the fast test path only. |
| Front-end tests | None (no Vitest, no Playwright) | Add Vitest for composables and utilities; Playwright smoke suite for login, checkout, forum post, support ticket. |
| Static analysis | None for PHP | Add Larastan at level 5, raise later. |
| Coverage | Not enforced | Report in CI; threshold on new code. |
| Flaky test | One unidentified test failed once earlier and never reproduced | Run the suite repeatedly in CI to identify it. |
| Formatting/linting | Pint, Prettier, ESLint, vue-tsc enforced | Keep. |
| Docs | README only; title still "Laravel Vue Starter"; app name inconsistent (`MetaForge`, `SPA Boilerplate`) | Rewrite README; add a `docs/` set (section 10); single source for the app name. |
| Release files | No CHANGELOG, CONTRIBUTING, SECURITY, issue/PR templates, CODEOWNERS | Add. |
| Local setup | Sail in dev dependencies; no documented Docker flow | Ship `compose.yaml` (app, MySQL, Redis, Mailpit, queue worker, Vite). |
| i18n | Only `lang/en`; 14 uses of the translator in the whole app; UI strings are hard-coded | **Decision: English-only in 1.0 with an extraction-ready structure** (section 8B). Bulk string extraction is 1.1. |
| Accessibility | Skip link, focus styles, reduced-motion support added; no audit | Run axe on the main flows; fix criticals. |
| Error pages | No custom 403/404/419/429/500/503 views (only maintenance) | Add branded error pages. |
| Install | Manual steps | Add `php artisan metaforge:install` (env checks, migrate, seed roles, create admin, sample data optional). |
| Versioning | No tags | Adopt SemVer, tag `v1.0.0-rc.N`, then `v1.0.0`; write upgrade notes. |

---

## 7. Payments architecture

This is the foundation for both the shop and Tebex.

### 7.1 Provider abstraction

Introduce a provider-neutral payments layer so Stripe and Tebex are interchangeable drivers.

```
app/Payments/
  Contracts/PaymentProvider.php     // capabilities + methods below
  PaymentManager.php                // resolves the active provider(s) from config
  Providers/StripeProvider.php      // wraps existing Cashier logic
  Providers/TebexProvider.php       // section 8
  Data/CheckoutSession.php          // provider, redirect_url|embed_token, reference
  Data/PaymentResult.php
  Webhooks/WebhookReceiver.php      // shared verify → store → queue pattern
```

Contract (sketch):

- `capabilities(): array` — one-time payments, subscriptions, refunds, coupons, hosted checkout, embedded checkout
- `startCheckout(Order $order, CheckoutContext $context): CheckoutSession`
- `handleWebhook(Request $request): WebhookOutcome`
- `refund(Payment $payment, ?Money $amount): RefundResult`
- `syncCatalogue(): void` (only providers that own a catalogue, such as Tebex)

### 7.2 Data model changes

New or changed tables (all provider-neutral, and all carrying the `owner_type`/`owner_id` pair described in section 7.4):

- `payments`: `order_id`, `provider`, `provider_reference`, `status`, `amount`, `currency`, `fee`, `raw` (json), timestamps. Unique on `(provider, provider_reference)`.
- `payment_webhook_calls`: generalise the existing `BillingWebhookCall` by adding `provider`, `external_id`, `signature_valid`, `processed_at`, `attempts`, `error`. Unique on `(provider, external_id)` for idempotency.
- `orders`: add `payment_provider`, `payment_status`, `placed_at`, `paid_at`, `fulfilled_at`, billing and shipping address snapshots, `customer_email`, `idempotency_key`.
- `subscriptions_external` (or `provider_subscriptions`): provider-neutral mirror of subscription state for providers that are not Cashier (Tebex recurring payments).
- `entitlements` and `user_entitlements`: what a plan or purchase grants (section 8.6).
- `audit_logs` (section 4).

### 7.3 Store-level provider rule (decided)

An order is paid through exactly one provider. In 1.0 the provider is a **store-level setting** in the ACP (`commerce.provider = stripe | tebex`), with a per-product override reserved for 1.1. This avoids mixed-provider carts, which would otherwise need split orders and split refunds.

Consequences to design for:
- Changing the provider while carts or pending orders exist: block the change in the ACP until pending orders are resolved, or let existing orders finish with their recorded provider (each order stores `payment_provider`).
- Subscriptions (plans) are separate from shop orders and keep using Stripe/Cashier unless the store provider is Tebex, in which case Tebex recurring packages are the subscription source. The pricing page reads from the active provider.
- Webhook routes for both providers stay registered so in-flight payments still settle after a switch.

### 7.4 Team-ready ownership model (decided: teams ship in 1.1)

1.0 has no teams, but nothing in 1.0 should have to be rewritten to add them. Rules for all new 1.0 schema and code:

- **Owner columns.** Every new billable or ownable table (`orders`, `payments`, `provider_subscriptions`, `user_entitlements`, `api tokens` metadata, `audit_logs`) carries `owner_type` and `owner_id` (a morph) in addition to any `user_id` that records the acting user. In 1.0, `owner_type` is always `App\Models\User` and `owner_id` equals the user. In 1.1 it can also be `App\Models\Team`.
- **Acting user versus owner.** Code that checks "may this person see/act on this record" goes through a policy that resolves the owner, never through `where('user_id', auth()->id())` directly. This is the main thing that makes the 1.1 change small.
- **Billing.** Provider-neutral records use the owner morph. Cashier's own tables keep `user_id` in 1.0; in 1.1 use `Cashier::useCustomerModel()` or a billable owner indirection. Keep all Cashier calls behind `SubscriptionManager` (already the case) so the swap is local.
- **Entitlements.** Resolved for an owner, not a user. A helper `Entitlements::for($owner)` returns the set; in 1.0 the owner is the user.
- **Permissions.** Keep global Spatie roles for site staff. Do not add team-scoped roles in 1.0; leave a documented extension point.
- **Tests.** Add a small test asserting that policies resolve through the owner so a future team owner cannot be bypassed.
- **Docs.** A short "Upgrading to teams (planned for 1.1)" note so adopters know what to expect.

---

## 8. Tebex support

### 8.1 What Tebex offers (from the official docs)

| Capability | Details |
|------------|---------|
| **Headless API** | `https://headless.tebex.io/api/accounts/{publicToken}/…`. Most endpoints need only the **public token**. Covers categories, packages, baskets, coupons, creator codes, gift cards, custom pages, sidebar modules and tiers. Endpoints needing user-specific data use HTTP Basic auth (public token as username, **private key** as password). |
| **Baskets** | `POST …/baskets` with `complete_url`, `cancel_url`, `custom` (your own data, returned in webhooks), `complete_auto_redirect`, and `ip_address` (required from a backend). Response includes `ident` and `links.checkout`. Packages are added with a separate endpoint; quantity can be updated and packages removed. |
| **Checkout** | Redirect to `https://pay.tebex.io/{ident}` (hosted) or embed with **Tebex.js**. |
| **Checkout API** | Lets you create baskets with **custom products on the fly**. **Requires prior approval from Tebex compliance** and is unavailable to some creators (including FiveM and RedM). |
| **Webhooks** | 13 event types: `payment.completed`, `payment.declined`, `payment.refunded`, `payment.dispute.opened/won/lost/closed`, `recurring-payment.started/renewed/ended`, `recurring-payment.cancellation.requested/aborted`, and `validation.webhook`. Payload: `{ id, type, date, subject }`. |
| **Webhook security** | `X-Signature` header: SHA-256 of the **raw body**, then HMAC-SHA-256 of that hash keyed with the webhook secret. Tebex sends only from two IPs (18.209.80.3, 54.87.231.232). Endpoint must be validated first: respond 200 and echo the validation webhook's `id`. Non-2xx triggers retries, then the webhook is marked failed. |
| **Merchant of record** | Tebex handles tax, payment methods and chargebacks. A copy of the basket is frozen when the customer begins paying. |
| **Game servers** | Tebex delivers purchased commands to game servers itself (Game Server API / plugins). Not needed by MetaForge. |

**Not verified in this review** (confirm against the docs and a sandbox store during M2): exact JSON for adding a package to a basket, the validation-webhook response format, Tebex.js script and frame hosts for the CSP, and API rate limits (none were stated in the pages I read).

### 8.2 Two supported modes

| Mode | Use when | Catalogue lives in | Notes |
|------|----------|--------------------|-------|
| **A. Headless (default for 1.0)** | Any Tebex store | Tebex (packages and categories managed in the Tebex panel) | MetaForge syncs and caches it, renders its own storefront, creates baskets, and sends customers to Tebex checkout. |
| **B. Checkout API (opt-in, behind a flag)** | Stores approved by Tebex compliance | MetaForge (its own products) | Custom products priced by MetaForge; same webhooks. Off by default (`TEBEX_CHECKOUT_API_ENABLED=false`). |

**Decision:** Headless (mode A) is the 1.0 deliverable. Mode B is built behind the flag and clearly documented as "requires Tebex approval". Because it cannot be fully tested without an approved account and sandbox, it ships as **experimental** in 1.0: covered by unit tests against recorded fixtures, documented as untested against live Tebex, and promoted to stable in 1.1 once exercised.

Both modes use the same webhook receiver, payment records and fulfilment pipeline, so B is an additional `startCheckout` path, not a second integration.

### 8.3 Implementation outline (M2)

1. **Config and secrets**: `config/payments.php` and `.env`: `TEBEX_PUBLIC_TOKEN`, `TEBEX_PRIVATE_KEY` (server-only, never sent to the browser), `TEBEX_WEBHOOK_SECRET`, `TEBEX_MODE`, `TEBEX_WEBHOOK_IPS` (configurable allow-list, since the IPs may change).
2. **HTTP client**: `TebexClient` using Laravel `Http` with timeouts, retries with backoff on 5xx/429, structured errors, and a fake for tests.
3. **Catalogue sync**: `tebex:sync` command and a scheduled job. Pulls categories with packages, upserts `tebex_categories` / `tebex_packages` (name, description, price, currency, type one-time vs subscription, image, sort, active), cached with a short TTL. The storefront reads from the local copy.
4. **Basket and checkout**: creating a basket from the MetaForge cart; set `custom` to `{ order_id, user_id, nonce }`; pass the customer's IP; pass `complete_url` and `cancel_url` pointing at MetaForge order pages; redirect to `links.checkout` (or mount Tebex.js with a CSP update). Pass-through for Tebex coupons, creator codes and gift cards (Tebex owns the discount rules).
5. **Return page**: `complete_url` shows "payment pending" and polls the order. **Never fulfil from the redirect**; only the webhook marks an order paid.
6. **Webhook endpoint** `POST /webhooks/tebex`: 404 for non-allow-listed IPs; verify `X-Signature` over the raw body with `hash_equals`; answer `validation.webhook` by echoing the `id`; store the call (unique on id); return 2xx immediately; process on the queue.
7. **Event handling** (queued, idempotent):
   - `payment.completed`: match the order via `custom.order_id`, then **verify the items and total against what MetaForge expects** (Tebex's own docs recommend this); mark paid; run fulfilment.
   - `payment.declined`: mark failed, notify.
   - `payment.refunded`: record the refund, revoke entitlements, restock where applicable.
   - `payment.dispute.*`: flag the order, notify admins, hold fulfilment of related items.
   - `recurring-payment.started/renewed/ended` and `…cancellation.requested/aborted`: maintain the provider-neutral subscription mirror and the user's entitlements.
8. **Fulfilment**: grant entitlements, roles or badges, licence keys and downloads; send order emails.
9. **ACP page "Tebex"**: connection test (one call to the store endpoint), last sync time and counts, webhook URL with validation steps, recent webhook calls with replay, and the mode switch.
10. **Account linking**: optional `external_accounts` row to store a Tebex username or id where a store requires one (only Minecraft/Overwolf stores require a username).
11. **Docs**: setup guide (create store, get tokens, set webhook URL, validate, test purchase), plus a troubleshooting table.

### 8.4 Tests

- Signature: valid, tampered body, wrong secret, missing header
- IP allow-list: allowed and blocked
- Validation webhook echo
- Idempotency: the same webhook id delivered twice grants once
- Amount or item mismatch rejects fulfilment and alerts
- Ordering: refund arriving before completion
- Catalogue sync: new, changed, removed packages
- Client behaviour with `Http::fake` (success, 422, 5xx, timeout)
- Subscription lifecycle events

### 8.5 Risks

- Tebex docs for the Headless API were recently reorganised; pin endpoint details against the live docs at implementation time.
- Mode B depends on Tebex compliance approval, which only you can obtain.
- Catalogue is owned by Tebex in mode A: MetaForge product editing screens do not apply to those items.
- Mixed Stripe/Tebex carts are intentionally unsupported in 1.0.

### 8.6 Entitlements (shared with the SaaS work)

A small provider-neutral entitlement layer is needed by Tebex purchases, Stripe plans and one-off products alike:

- `entitlements` (key, label, type: feature | limit | role | badge | download)
- `plan_entitlements` and `product_entitlements` (value or limit)
- `user_entitlements` (source: stripe | tebex | admin, expires_at)
- Helper `$user->can_use('feature')`, `@entitled` frontend prop, and a route middleware `entitled:feature,limit`

---

## 8A. Search with Meilisearch (decided: ships in 1.0)

**Goal:** fast, typo-tolerant, relevance-ranked search across blog, forum, FAQs and shop products that scales beyond what `LIKE` queries can, with a safe fallback for installs that do not run Meilisearch.

**Design**
- **Engine:** Laravel Scout with the Meilisearch driver. `config/search.php` `driver` becomes `meilisearch | database` (the broken `mysql_fulltext` option is removed).
- **Indexes:** `blogs`, `forum_threads` (title plus first post and replies as a bounded text field), `faqs`, `products`. Optional later: member directory.
- **Visibility is enforced at index time.** `shouldBeSearchable()` excludes drafts, unpublished threads, boards restricted by permission, inactive products. Content that changes visibility is re-indexed or removed through model observers (the forum already has `ForumIndexCacheObserver` as a pattern).
- **Index settings** (searchable, filterable, sortable attributes, ranking rules, stop words, typo tolerance) live in code (`SearchIndexSettings`) and are applied by `php artisan metaforge:search:sync`, so a fresh install configures itself.
- **Indexing** runs on the queue with batching; a `metaforge:search:reindex` command rebuilds from scratch (zero-downtime via index swap).
- **Keys:** the application uses a server-side search key; the master key is never used at runtime and never sent to the browser.
- **Fallback:** `GlobalSearchService` keeps the `database` path, made portable (case-insensitive on PostgreSQL). If Meilisearch is unreachable, a short circuit breaker serves the database path and logs a warning.
- **Existing features keep working:** query logging and aggregation (`SearchQuery`, analytics page), result grouping, pagination and highlight snippets (use Meilisearch's crop/highlight output when present).
- **Operations:** health check in ACP diagnostics (reachable, index document counts, last sync), `compose.yaml` service, and a deployment note for Meilisearch Cloud versus self-hosted.
- **Tests:** unit tests against Scout's collection engine for visibility rules; a CI job with a Meilisearch container for integration tests (relevance order, typo tolerance, filtering, removal on unpublish); database-fallback tests on both MySQL and PostgreSQL.
- **Effort:** 4–6 days (inside M6).

---

## 8B. Internationalisation structure (decided: English-only in 1.0)

**Goal:** make adding a language in 1.1 a translation task, not a refactor, without taking on bulk string extraction now.

**Built in 1.0**
- **Backend:** standard Laravel `lang/en/*.php` files grouped by area (`auth`, `validation`, `billing`, `shop`, `support`, `forum`, `blog`, `mail`, `ui`). A `SetLocale` middleware resolves the locale from the signed-in user's `locale` column (it already exists), then a cookie, then `Accept-Language`, limited to an allow-list in config (just `en` in 1.0).
- **Front end:** a small, SSR-safe `useI18n()` composable exposing `t(key, params)` and plural support through `Intl.PluralRules`. Translations for the active locale reach the page as a shared Inertia prop (split by area so pages do not ship every string). Chosen over a full i18n library to keep the bundle small; swapping to `vue-i18n` later stays possible because the `t()` surface is the same.
- **Formatting:** dates, numbers and currency use `Intl` with the active locale (the shared dayjs setup gets the locale too). Emails use the notifiable's locale.
- **Converted to keys in 1.0:** header, footer, auth pages, settings navigation, error pages, validation messages, all notification emails, and every new screen written in this plan (checkout, orders, entitlements, legal, Tebex admin). Remaining pages keep literal strings.
- **Conventions and tooling:** new code must use keys; `vue/no-bare-strings-in-template` runs in warning mode on the converted folders; a `metaforge:lang:check` command fails CI if a locale is missing keys that `en` has; use logical CSS utilities (`ms-`, `me-`, `ps-`, `pe-`) in new code so a right-to-left layout is feasible later.
- **Docs:** "Adding a language" guide.

**1.1:** bulk extraction of the remaining pages, first additional locales, RTL support, localised slugs and SEO `hreflang`.

- **Effort:** 4–6 days (inside M7, with the converted areas done by their owning milestone).

---

## 9. Milestones

Dependencies: M0 → M1 → M2. M3 can start after M1. M4 and M5 are independent of M2/M3. M6 follows M1 (search needs the shop's products). M7 closes. Each milestone converts its own new screens and emails to translation keys (section 8B).

### M0. Foundations and release hygiene (4–6 days)

- Fix the support-attachment privacy defect (private disk, authorised download, migration of existing files)
- Remove the broken `mysql_fulltext` search option; make the `database` fallback portable (case-insensitive on PostgreSQL)
- Database portability audit of every raw SQL call; route engine differences through the `Sql` helper
- CI matrix: **MySQL 8 and PostgreSQL 16** (SQLite remains the fast local path); Larastan; repeat-run to find the flaky test; secrets scan
- Single source for the app name; fix README title
- `SECURITY.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, PR/issue templates, CODEOWNERS, Dependabot
- `compose.yaml` (MySQL, Redis, Meilisearch, Mailpit, queue worker)
- i18n infrastructure (section 8B): `SetLocale`, `useI18n()`, `lang` layout, `lang:check`
- **Exit:** CI green on MySQL 8 and PostgreSQL 16; no known high-severity findings open

### M1. Payments foundation and a working shop (15–20 days)

- Payment abstraction, `payments` and generalised webhook-calls tables; refactor Stripe behind the contract
- Team-ready ownership model on all new tables and policies (section 7.4)
- Checkout: customer details, address book, shipping zones and rates, tax (Stripe Tax or a configurable rate table), order creation, stock reservation, payment, confirmation page, emails
- Store-level provider setting (section 7.3)
- Order lifecycle and ACP order management (view, status changes, notes, refund)
- Coupons and discount codes; gift cards (first to cut if the date is at risk)
- ACP catalogue editing (update/delete), product images and media, inventory adjustments
- Digital goods: downloads with signed URLs, licence keys
- Reviews and wishlists (second to cut, after gift cards)
- **Exit:** a guest and a signed-in customer can buy physical and digital products end to end with Stripe; orders, refunds and stock are consistent; covered by feature and browser tests

### M2. Tebex integration (9–13 days)

Section 8 in full: client, catalogue sync, baskets and checkout, webhook receiver, event handlers, fulfilment, ACP page, docs, tests. Checkout API mode (B) is built behind `TEBEX_CHECKOUT_API_ENABLED` and ships as experimental.

**Sequencing (decided):** no Tebex store exists yet; one will be set up when we are ready to test. M2 is therefore split in two so it never blocks other work:
- **M2a, build against fixtures (no Tebex account needed):** the client, catalogue sync, basket creation, webhook receiver (signature, IP allow-list, validation echo, idempotency), event handlers, fulfilment, ACP page and docs, all tested with `Http::fake` and recorded/hand-written payload fixtures. Exit: the whole suite is green without network access. This is most of the work.
- **M2b, live verification (needs your store):** once the store exists, confirm the unverified points listed in section 8.1 (add-package request shape, validation-reply format, Tebex.js hosts for the CSP, rate limits), run a real test purchase, refund and recurring payment, and correct the fixtures to match reality. Plan on a short session together for this; it needs a public webhook URL (a tunnel is fine for testing).
- M2b must be done before the first release candidate, but nothing else in the plan depends on it.
- **Exit:** a test purchase in a Tebex sandbox store completes end to end in mode A; refund and dispute events reverse entitlements; webhook tests pass; docs written; mode B covered by fixture-based tests and labelled experimental.

### M3. SaaS essentials (10–14 days)

- Entitlements (section 8.6) wired to Stripe plans and the pricing page
- Trials, coupons on subscriptions, dunning emails, customer portal links
- Metered usage example with reporting
- Self-service API keys page with scopes and expiry
- Admin audit log and read-only impersonation
- Onboarding checklist; branded email layout
- Tests asserting that policies resolve through the owner, so teams can be added in 1.1 (section 7.4)
- **Exit:** a plan change visibly changes what a user can do; limits are enforced; audit log records admin actions.

### M4. Community completions (8–12 days)

- Public member profiles and directory; follow
- Forum: thread tags/prefixes, solved answers, attachments, ignore list, RSS
- Moderation: infractions and ban history, automod rules, spam guard extended from blog comments to forum posts
- Email digests
- Break up the largest controllers while touching them
- **Exit:** a moderator can handle a spam wave and a rule-breaking member entirely from the ACP.

### M5. Security and compliance (5–7 days)

- Section 4.2 checklist; upload pipeline; token defaults
- Legal pages (Terms, Privacy, Cookie policy) managed in the ACP; cookie-consent banner
- Accessibility pass (axe on main flows)
- **Exit:** checklist complete; external dependency audit clean; no open high/medium findings. (Passkeys are deferred to 1.1.)

### M6. Search and performance (8–12 days)

- Meilisearch search (section 8A): indexes, visibility rules, sync/reindex commands, fallback, ACP health, CI integration job
- Redis defaults for production; Horizon option; Octane notes
- Image pipeline with responsive variants; CDN notes
- Guest HTTP caching; query-count regression tests; bundle and Lighthouse budgets in CI
- **Exit:** section 5.2 targets met on a reference setup; budgets enforced in CI; search works with and without Meilisearch.

### M7. Quality, docs and release (11–15 days)

- Playwright smoke suite; Vitest unit tests; coverage reporting
- Documentation set: install, configuration, architecture, payments (Stripe and Tebex), search, theming and rebranding, adding a language, extending modules, deployment (Forge/Docker), upgrade guide (including "teams in 1.1"), API reference
- `metaforge:install` command; demo seeders
- Branded error pages; finish i18n conversion of shared chrome, auth, emails and error pages
- Third-party licence audit and `THIRD-PARTY-NOTICES.md`
- Release candidates `v1.0.0-rc.1…`, bug bash, then tag `v1.0.0`
- **Exit:** section 10 gates all green.

**Total: about 70–100 developer-days** for the decided scope. Teams (about 8–12 days) and passkeys (about 4–6) are out, but Meilisearch (4–6), PostgreSQL support and the i18n structure (about 4–6) are in. **No scope is being cut now.** If the date is ever at risk, the agreed cut order is: gift cards first (about 2 days), then reviews and wishlists (about 3–4 days).

---

## 10. v1.0.0 release gates

A release candidate is cut only when every box is checked.

**Functional**
- [ ] Guest and customer checkout works end to end with Stripe (physical and digital goods)
- [ ] Tebex purchase, refund and subscription events verified against a real store end to end (mode A; M2b)
- [ ] Plans and purchases grant and revoke entitlements
- [ ] Support, forum, blog, polls, shop and search work on MySQL 8 **and** PostgreSQL 16
- [ ] Search works with Meilisearch and falls back cleanly without it

**Security**
- [ ] All items in section 4.2 done; `composer audit` and `npm audit` clean
- [ ] Private uploads; authorised downloads
- [ ] Webhook endpoints verified and idempotent for both providers

**Quality**
- [ ] CI green on the MySQL 8 and PostgreSQL 16 matrix; Larastan clean at the agreed level
- [ ] Playwright smoke suite green on the main flows
- [ ] No flaky tests; no lazy-loading violations in the test suite
- [ ] `lang:check` passes

**Performance**
- [ ] Targets in section 5.2 met; budgets enforced in CI

**Product and docs**
- [ ] Legal pages, cookie consent and error pages shipped
- [ ] README, install guide, payments guides, upgrade guide, API reference
- [ ] `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md`, templates, `THIRD-PARTY-NOTICES.md`
- [ ] `metaforge:install` works on a clean machine from the docs alone

---

## 11. Decisions

### Resolved

| # | Question | Decision |
|---|----------|----------|
| 1 | Teams/organisations in 1.0? | **No.** Ship 1.0 without teams; schema and policies are team-ready (section 7.4); teams arrive in 1.1. |
| 2 | Tebex mode | **Headless API for 1.0.** Checkout API built behind a flag and shipped as experimental. |
| 3 | Provider scope | **Store-level** provider setting for 1.0 (section 7.3). Per-product override in 1.1. |
| 4 | Databases | **MySQL 8 and PostgreSQL 16 in CI.** SQLite for local development and tests only. |
| 5 | Languages | **English-only** with an extraction-ready structure (section 8B). |
| 6 | Search at scale | **Meilisearch ships in 1.0** (section 8A). |
| 7 | Licence | **MIT** (my recommendation; see below). |
| 8 | Passkeys | **1.1.** |
| 9 | Scope trims | **None for now.** If needed later, cut gift cards first, then reviews/wishlists. |
| 10 | Tebex test access | **Not available yet.** A store will be created when ready to test; M2 is split into M2a (fixtures) and M2b (live verification). |
| 11 | Tebex Checkout API approval | **Will be requested when ready to test.** Mode B stays experimental until then. |
| 12 | Meilisearch hosting | **Accepted:** self-hosted via `compose.yaml` as the documented path, Meilisearch Cloud as the alternative. |

### Licence recommendation: keep MIT

The repository is already MIT (`LICENSE.md` and `"license": "MIT"` in `composer.json`). I recommend keeping it:

- It matches the Laravel ecosystem and the official starter kits. Adopters of a boilerplate expect to fork it, rebrand it and sell what they build, which MIT allows without friction.
- **I have not yet audited the dependencies' licences**, so this is unconfirmed. The major frameworks used here (Laravel, Vue, Inertia, Tailwind and similar) are commonly MIT-licensed, but that must be checked, not assumed. M7 adds a licence audit (for example `composer licenses` and a Node licence checker) and a `THIRD-PARTY-NOTICES.md`; any copyleft dependency would need a decision. The audit should also cover the Inter font and the technology logos on the home page, whose trademarks are not covered by MIT.
- A permissive licence does not stop you earning from the project: paid support, a hosted version, premium add-on modules, templates and services are the usual routes.
- **Trade-off to know:** MIT cannot be taken back for versions already released. If you might want a commercial licence or a paid "pro" edition later, decide before tagging 1.0; future versions can be licensed differently, but anyone who already has 1.0 keeps MIT rights to it.
- Tidy-up: `LICENSE.md` names an individual and personal email as the copyright holder. Confirm that is how you want it to read (an organisation name is more usual for a product).

This is a recommendation, not legal advice; have a lawyer review it if the licence matters commercially.

### Still open

1. **Copyright holder line** in `LICENSE.md` (see the licence note above): confirm whether it should stay a personal name and email or become an organisation name.
2. **When to set up the Tebex store** (for M2b). Nothing is needed before then.

---

## 12. Out of scope for 1.0 (candidates for 1.x)

**Planned for 1.1:** teams/organisations (with team-scoped roles and billing), passkeys (WebAuthn), per-product payment provider, Tebex Checkout API promoted from experimental to stable, bulk string extraction and the first additional locales (including RTL).

**Later candidates:** multi-vendor marketplace, native mobile apps, a GraphQL API, a public plugin marketplace, multi-currency price lists with live FX, advanced analytics and BI exports, live chat, a visual page builder, A/B testing, and game-server delivery integrations beyond what Tebex already provides.

---

## Appendix A. Evidence

- Shop controllers: `Ecommerce/CartController`, `Ecommerce/OrderController` (read-only), `Ecommerce/ProductCatalogController`. No checkout route in `routes/web.php`.
- Admin commerce: `Admin/CommerceController` has only `storeProduct`, `storeBrand`, `storeOption`, `storeOptionValue`, `storeVariant`, `storePrice`, `storeInventory`.
- Attachments: `SupportCenterController` lines ~329–360, `$disk = 'public'`.
- Search: `config/search.php` default `database`; `GlobalSearchService::supportsFullText()`; no FULLTEXT migration anywhere in `database/`.
- Defaults: `.env.example` has `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`.
- Tests: `phpunit.xml` uses SQLite in-memory; CI runs `php artisan test` only.
- Scheduler: three jobs in `bootstrap/app.php`.
- Policies: `BlogCommentPolicy`, `BlogPolicy`, `ForumPostPolicy`.

## Appendix B. Tebex sources

- Headless API: <https://docs.tebex.io/developers/headless-api/guides/baskets/create-a-basket>
- Authorization: <https://docs.tebex.io/developers/headless-api/authorization>
- Checkout API: <https://docs.tebex.io/developers/checkout-api/overview>
- Webhooks: <https://docs.tebex.io/developers/webhooks/overview>
- Checkout webhooks: <https://docs.tebex.io/developers/checkout-api/checkout-webhooks>
- Tebex.js: <https://docs.tebex.io/developers/tebex.js/overview>
