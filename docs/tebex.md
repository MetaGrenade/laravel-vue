# Tebex

[Tebex](https://tebex.io) is a merchant of record for digital goods (game servers and communities above all): it keeps the catalogue, takes the payment, handles tax and chargebacks, and tells the shop when someone has paid. MetaForge supports it as the second payment provider, behind the same contract Stripe uses (see [Payment providers](commerce.md#payment-providers)).

> **Status: the catalogue is in; buying is not.** This is the first of the Tebex slices (M2a, built and tested against fixtures, no Tebex account needed). What exists today is the connection, the local copy of the catalogue and the ACP page. Still to come, each as its own change: baskets and checkout, the webhook endpoint and its event handlers, recurring payments and entitlements, and the experimental Checkout API mode. A live check against a real store (M2b) comes before a release candidate. See [the roadmap](ROADMAP-v1.0.0.md) (M2).

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

## Keeping the copy fresh

`php artisan tebex:sync` fetches the store's categories with their packages and makes the local copy match. It runs:

- from the scheduler, every `TEBEX_SYNC_EVERY_MINUTES` minutes (default 60; the scheduler looks every five minutes, so 5 is the smallest useful value; `0` turns the schedule off) once the public token is set. This needs the scheduler (`php artisan schedule:run` every minute) like the rest of the shop;
- from **Refresh catalogue** in the ACP, which runs it at once for the person who pressed it;
- from the command line.

What a run does:

- A category or package that is **new** is added, one that **changed** is updated, and one Tebex **no longer lists** is stamped `removed_at` and kept: nothing is ever deleted, because an order will refer to the package it was bought as. If it reappears, it is listed again.
- A run is **all or nothing**. Everything is fetched first and applied in one transaction, so a failure part way (Tebex down, an answer that makes no sense, an entry with no id) leaves the copy as it was. The run is recorded as failed with the reason, and the ACP shows when the copy was last good.
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

## Permissions

The page needs `commerce.acp.view`. **Test connection** and **Refresh catalogue** each call Tebex, so they need `commerce.acp.edit` and are rate limited (10 and 6 a minute). No new permission, so no seeder to run again.

## Troubleshooting

| What you see | What it means |
|--------------|---------------|
| "Tebex is not set up" | `TEBEX_PUBLIC_TOKEN` is empty. Set it and clear the config cache (`php artisan config:clear`). |
| "Tebex answered 404: it does not know this store" | The public token is wrong or belongs to a deleted store. |
| "Tebex answered 401/403: it refused the credentials" | The token or key was rotated or revoked in the Tebex panel. |
| "Tebex answered 5xx" or "could not be reached" | Tebex or the network is down; it was already retried. The next scheduled run tries again, or press the button. |
| "A synchronisation is already running" | Another run holds the lock (it is released when the run ends, or after five minutes). |
| "Tebex sent a package the shop could not understand" | An entry had no id. Nothing was changed. Please report it with the response, since it means the shop's idea of Tebex's format is out of date. |
| The page is empty after a successful run | The store has no categories, or its packages are not in any category Tebex lists. |
| Prices differ from the Tebex store page | Tebex works out sales tax for the buyer's country. The copy has the price before tax, and the tax and total as Tebex quoted them to the shop's own request, which need not be the buyer's. |

## Testing

The whole thing is tested without a network: `Http::fake()` answers with the files in `tests/Fixtures/tebex`, which were written from Tebex's published [OpenAPI schema](https://github.com/tebexio/TebexHeadless-OpenAPI) and [webhook documentation](https://docs.tebex.io/developers/webhooks/overview). `Tests\Support\InteractsWithTebex` sets it up. A fixture cannot prove that real Tebex sends exactly that, which is why the milestone has a live check (M2b) that compares the fixtures with a real store before a release candidate.

## Sources

- Headless API: <https://docs.tebex.io/developers/headless-api/introduction>
- Endpoints: <https://docs.tebex.io/developers/headless-api/endpoints>
- Webhooks: <https://docs.tebex.io/developers/webhooks/overview>
- Testing: <https://docs.tebex.io/developers/headless-api/testing>
