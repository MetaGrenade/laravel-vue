# Tebex

[Tebex](https://tebex.io) is a merchant of record for digital goods (game servers and communities above all): it keeps the catalogue, takes the payment, handles tax and chargebacks, and tells the shop when someone has paid. MetaForge supports it as the second payment provider, behind the same contract Stripe uses (see [Payment providers](commerce.md#payment-providers)).

> **Status: a basket can be made; a payment cannot be settled yet.** Tebex is built as slices (M2a, built and tested against fixtures, no Tebex account needed). Done: the connection, the local copy of the catalogue and the ACP page; and now the storefront, the cart, the order and the basket at Tebex. Still to come, each as its own change: **the webhook endpoint and its event handlers (without which an order is never marked paid)**, then an **embedded checkout** (Tebex.js: the buyer pays without leaving the shop), recurring payments and entitlements, and the experimental Checkout API mode. Until the webhook slice lands, Tebex cannot be chosen as the store's provider unless `TEBEX_CHECKOUT_PREVIEW` is set, and that is only for trying a basket against a store, never for a shop that takes money. A live check against a real store (M2b) comes before a release candidate. See [the roadmap](ROADMAP-v1.0.0.md) (M2).

## How it fits

In the mode MetaForge ships with (the Tebex **Headless API**) the catalogue lives in Tebex: staff add packages, set prices and write descriptions in the Tebex panel. MetaForge keeps a **local copy** that it refreshes, draws its own storefront from, and sends the customer to Tebex's hosted checkout with. Tebex owns discounts, tax and payment methods; the shop never sees a card.

Nothing about the catalogue is edited in MetaForge. The pages that manage products, prices and stock apply to the shop's own products, not to Tebex packages.

## Setting up

1. In the Tebex panel open **Developers → API Keys** and copy the store's **public token** (it starts with four characters and a dash, like `spk3-d515a30...`) and its **private key**.
2. Put them in `.env`:

   ```
   TEBEX_PUBLIC_TOKEN=spk3-...
   TEBEX_PRIVATE_KEY=...
   ```

   The public token is all that is needed to read the catalogue. The private key is only for calls that return a customer's own data, and nothing uses it yet; it is optional until a feature that does arrives. Neither is ever sent to the browser, shown in full in the ACP or written to a log.
3. Run `php artisan migrate`, then `php artisan tebex:sync` (or press **Refresh catalogue** in the ACP).
4. Open **Commerce → Tebex** in the ACP and press **Test connection**: it asks Tebex which store the token belongs to and what currency it sells in.

Tebex has **no sandbox**. To try a purchase later you switch the store to *Test Mode* in the Tebex panel (Settings → Checkout), which adds a payment method that always succeeds and fires real webhooks. Turn it off, and remove the test payments, before the store is public.

## Selling through Tebex

When Tebex is the store's payment provider (System settings, or `COMMERCE_PROVIDER=tebex`) the shop sells packages instead of products:

- **`/shop` goes to `/shop/packages`**, the catalogue read from the local copy (never from Tebex while the visitor waits), with search, categories and paging. A package's page shows its pictures and description (HTML from the Tebex panel, **cleaned on the server**: scripts, frames, styles and event handlers are removed, and only web addresses are used for pictures), its price *before tax*, and an Add to cart button.
- **The cart holds packages or products, never both**, and all the packages in it are in one currency (the cart is in their currency, not the shop's). A package that cannot be bought more than once is held to one, and a per-customer limit Tebex sets is kept to. The shop's own products cannot be added while Tebex is the provider, and a cart left over from the other kind is refused at checkout with a message, since the provider can change while a cart waits.
- **What the shop does not do for packages.** Tebex is the merchant of record, so it works out the tax, applies its own coupons, creator codes and gift cards and takes the payment. The shop's own discount codes, gift cards, shipping and tax are not used with packages, and the cart and checkout pages say so. No address is asked for. The order carries the shop's quote (each package at the price before tax Tebex quoted when the catalogue was last copied, times the quantity, so the grand total is that sum); what the buyer really pays is Tebex's, and the payment records what Tebex quoted for the basket (`payments.raw.quoted`), and whether it differed from the shop's quote (`price_differs`, also logged as a warning).
- **Not sold from here yet:** a package that needs the buyer to choose something (a server, a rank: Tebex `variables`), a free package (Tebex takes no payment for it, so nothing would tell the shop it was bought), a recurring package's entitlements (the next slices), and a store whose platform needs the buyer to sign in or give a username (Minecraft, Steam games such as Rust or FiveM, Discord, Overwolf and the rest: everything but Tebex's **"Universal (No Auth)"** platform, which the official SDK treats as the only one that needs no basket authorisation). The shop does not do that step yet, so it **sells only for a Universal (No Auth) store** and says so for any other: see [Which store it can sell for](#which-store-it-can-sell-for). The storefront shows why a package cannot be bought instead of an Add to cart button.

### Which store it can sell for

Tebex stores are set up for a **platform**, and every platform except **"Universal (No Auth)"** (Minecraft, Steam games such as Rust or FiveM, Discord, Overwolf and the rest) needs the buyer to sign in or give a username and authorise the basket before they can pay. The shop does not do that step, so it only sells for a Universal (No Auth) store, which is also what a shop selling software, memberships or community perks is.

- What Tebex says about the store (its name, currency and platform) is **remembered whenever Tebex is asked**: by every catalogue synchronisation and by **Test connection**. Nothing waits for Tebex to answer it afterwards.
- For any other platform (or one the shop has never heard of, such as a game added later): the ACP page says the shop cannot sell for the store and what it can sell for, the storefront shows every package as not for sale with that reason, **Add to cart refuses**, the cart says checkout is not available, Tebex does not show as ready in System settings, and **no basket is made** even if a stale form posts one.
- Until the store has been looked at once (an installation that synchronised before this check existed, until its next scheduled synchronisation or a click on Test connection) the platform is unknown and nothing is refused; so is a store that does not say what it is set up for. The check changes with the store: when it is changed in the Tebex panel, the next synchronisation lifts or applies the refusal. A synchronisation that fails keeps what the shop knew.
- The platform is taken from Tebex's own list, which the official SDK also uses: Universal (No Auth) is the one it treats as needing no authorisation, and it refuses a platform it does not know.

### What checkout does

1. The cart is priced again from the copy, strictly: a package Tebex no longer lists, one that has expired or gained choices, a quantity beyond what it allows, or a free one stops the order before Tebex is asked anything.
2. An order is placed (pending, with the packages as its lines, `payment_provider = tebex`). Stock does not come into it.
3. A **basket** is made at Tebex: where the buyer comes back to (a signed link to the order page, and the cart for "cancel"), the order's public id and a random **nonce** (kept on the payment) as the basket's `custom` data, and the buyer's address (so Tebex works out their tax). Each package is added by its `ident`. The buyer is sent to the basket's checkout link.
4. The payment is recorded as pending, under the basket's identifier.
5. **Coming back from Tebex settles nothing.** The order page says it is waiting for the provider to confirm, and only Tebex's webhook (next slice) marks an order paid.

A call that changes something at Tebex (making a basket, adding a package) is not repeated after a failure that may have got through, only after a 429, since doing it twice would put two of a package in a basket. If any step fails the order is cancelled and the buyer told to try again; a half-made basket at Tebex is harmless.

### Starting again, and running out of time

Tebex has no call that cancels a basket, so a checkout the shop is done with (the buyer starts again, or the order's window passes) is closed by **taking the packages out of it**: a basket with nothing in it has nothing to pay for. The shop asks Tebex for the basket, and only calls it closed if, after taking everything out, Tebex says it is empty and unpaid. A basket that is already paid is not touched, and is reported as not settled, so the buyer is told their earlier payment is still being confirmed rather than being offered a second basket to pay. If Tebex cannot be asked, or will not empty the basket, nothing is replaced.

## Trying a basket before the webhook exists

`TEBEX_CHECKOUT_PREVIEW=true` registers Tebex as a payment provider so that you can choose it (System settings, or `COMMERCE_PROVIDER=tebex`) and watch a basket being made at a real store in Test Mode. **An order paid this way is never marked paid** (nothing receives Tebex's webhook yet) and expires unpaid, so never use it on a shop that takes money. It goes away when the webhook slice lands.

## Keeping the copy fresh

`php artisan tebex:sync` fetches the store's categories with their packages and makes the local copy match. It runs:

- from the scheduler, every `TEBEX_SYNC_EVERY_MINUTES` minutes (default 60; the scheduler looks every five minutes, so 5 is the smallest useful value; `0` turns the schedule off) once the public token is set. This needs the scheduler (`php artisan schedule:run` every minute) like the rest of the shop;
- from **Refresh catalogue** in the ACP, which runs it at once for the person who pressed it;
- from the command line.

What a run does:

- A category or package that is **new** is added, one that **changed** is updated, and one Tebex **no longer lists** is stamped `removed_at` and kept: nothing is ever deleted, because an order will refer to the package it was bought as. If it reappears, it is listed again.
- A run is **all or nothing**. Everything is fetched first and applied in one transaction, so a failure part way (Tebex down, an answer that makes no sense, a category or package that is not an object, an entry with no id) leaves the copy as it was. An entry the shop cannot read is never skipped, because a skipped one would be taken for one Tebex no longer lists. The run is recorded as failed with the reason, and the ACP shows when the copy was last good.
- Only **one run at a time**. A second one started meanwhile is refused (the scheduler's quietly skips). A run that died without finishing is closed off by the next one.
- A store that Tebex reports with **no categories** marks everything as no longer listed. That is what Tebex said; the next run that sees the packages again restores them.
- Tebex errors are retried when they might pass (a network failure, a 5xx, a 429) after 0.5, 1.5 and 4 seconds, and not otherwise. A scheduled run that fails is recorded, not thrown, so a store that is down for an hour does not fill the failed-jobs table, and it is not retried until the next interval.

The ACP page shows the connection, the counts (categories, packages, recurring packages, no longer listed), the result of the last run, a filterable list of packages and the last ten runs. The last fifty runs are kept.

### What is stored

For each package: Tebex's id and `ident` (what a basket is built from), its category, name, the description (HTML written in the Tebex panel, so it is **cleaned before it is ever shown**), picture, whether it is one-off or recurring, the price before tax, the sales tax and total Tebex quoted in that answer (what a buyer really pays is worked out by Tebex at checkout, for their own country), any discount, the currency, limits (quantity, gifting, per customer), media, and the **variables** a buyer must choose (a server, a rank). A package that has variables cannot be bought without choosing them, which the checkout slice handles.

Prices are kept to the cent. Tebex sends them as floating-point numbers, so they are rounded to two decimals on the way in. Each package keeps the currency Tebex gave it; if that is not the shop's currency (`COMMERCE_CURRENCY`) the ACP says so, and the package is shown and sold in its own.

## Configuration

`config/tebex.php`:

| Setting | Default | Meaning |
|---------|---------|---------|
| `TEBEX_PUBLIC_TOKEN` | none | The store's public token. Nothing works without it. |
| `TEBEX_PRIVATE_KEY` | none | The store's private key. Server only; optional for now. |
| `TEBEX_API_URL` | `https://headless.tebex.io/api` | The Headless API. Change it only to point at a test double. |
| `TEBEX_HTTP_TIMEOUT` | `10` | Seconds to wait for an answer. |
| `TEBEX_HTTP_CONNECT_TIMEOUT` | `5` | Seconds to wait for a connection. |
| `TEBEX_SYNC_EVERY_MINUTES` | `60` | How often the scheduler refreshes the copy; `0` is never. |
| `TEBEX_CHECKOUT_PREVIEW` | `false` | Register Tebex as a provider before its webhooks are handled. For trying baskets only. |

## Permissions

The page needs `commerce.acp.view`. **Test connection** and **Refresh catalogue** each call Tebex, so they need `commerce.acp.edit` and are rate limited (10 and 6 a minute). No new permission, so no seeder to run again.

## Troubleshooting

| What you see | What it means |
|--------------|---------------|
| "Tebex is not set up" | `TEBEX_PUBLIC_TOKEN` is empty. Set it and clear the config cache (`php artisan config:clear`). |
| "Tebex answered 404: it does not know this store" | The public token is wrong or belongs to a deleted store. |
| "Tebex answered 401/403: it refused the credentials" | The token or key was rotated or revoked in the Tebex panel. |
| "Tebex answered 5xx" or "could not be reached" | Tebex or the network is down; it was already retried. The next scheduled run tries again, or press the button. |
| "This Tebex store is set up for ..., where buyers have to sign in" | The store's platform is not Universal (No Auth). Create a Universal (No Auth) store in the Tebex panel, or wait for the shop to support sign-in. Checking again after changing the platform: **Test connection** or **Refresh catalogue**. |
| "What is in your cart is not sold the way this shop takes payment now" | The store's provider changed while a cart of the other kind was waiting. Empty it and add something again. |
| "Tebex priced order ... in EUR, not USD" | The basket came back in another currency than the packages were copied in. Refresh the catalogue; if it persists the store's currency changed in the Tebex panel. |
| "Tebex quoted a different price than the shop did" (in the log) | The copy is out of date: Tebex's price moved since the last synchronisation. The buyer paid what Tebex charged; refresh the catalogue. |
| "A synchronisation is already running" | Another run holds the lock (it is released when the run ends, or after five minutes). |
| "Tebex sent a package (or category, or list of packages) the shop could not understand" | An entry had no id, was not an object, or the list was not a list. Nothing was changed. Please report it with the response, since it means the shop's idea of Tebex's format is out of date. |
| The page is empty after a successful run | The store has no categories, or its packages are not in any category Tebex lists. |
| Prices differ from the Tebex store page | Tebex works out sales tax for the buyer's country. The copy has the price before tax, and the tax and total as Tebex quoted them to the shop's own request, which need not be the buyer's. |

## Why not the official SDK

Tebex publishes a PHP SDK (`tebex/tebex-sdk-php`, MIT, PHP 7.4+, Guzzle). The shop has its own small client (`TebexClient`) over Laravel's HTTP layer instead, on purpose: it can be tested with `Http::fake()` like everything else, it has one place that decides what is repeated after a failure (a call that changes something is not, after anything but a 429), and it takes the store's token out of every message. The SDK is still useful as Tebex's own reference: reading its source settled which platform needs no basket authorisation, confirmed the add-package path, and revealed two webhook events the documentation does not list (`basket.abandoned`, `recurring-payment.status-changed`). Its webhook parsing is not used because it reads the request itself (and appears to fail when it does), compares the signature with `strcmp` rather than a constant-time comparison, and keeps the secret in a static property, none of which suits a queue-processed, replay-safe receiver.

## Testing

The whole thing is tested without a network: `Http::fake()` answers with the files in `tests/Fixtures/tebex`, which were written from Tebex's published [OpenAPI schema](https://github.com/tebexio/TebexHeadless-OpenAPI) and [webhook documentation](https://docs.tebex.io/developers/webhooks/overview). `Tests\Support\InteractsWithTebex` sets it up. A fixture cannot prove that real Tebex sends exactly that, which is why the milestone has a live check (M2b) that compares the fixtures with a real store before a release candidate.

## Sources

- Headless API: <https://docs.tebex.io/developers/headless-api/introduction>
- Endpoints: <https://docs.tebex.io/developers/headless-api/endpoints>
- Webhooks: <https://docs.tebex.io/developers/webhooks/overview>
- Testing: <https://docs.tebex.io/developers/headless-api/testing>
