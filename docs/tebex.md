# Tebex

[Tebex](https://tebex.io) is a merchant of record for digital goods (game servers and communities above all): it keeps the catalogue, takes the payment, handles tax and chargebacks, and tells the shop when someone has paid. MetaForge supports it as the second payment provider, behind the same contract Stripe uses (see [Payment providers](commerce.md#payment-providers)).

> **Status: a one-off package can be sold and paid for, and the order is settled by Tebex's webhook.** Tebex is built as slices (M2a, built and tested against fixtures, no Tebex account needed). Done: the connection, the local copy of the catalogue and the ACP page; the storefront, the cart, the order and the basket at Tebex; **selling for the games** (a username for Minecraft and Overwolf, signing in for Discord, FiveM, Rust and the other Steam-based games, as well as Universal (No Auth)); and **the webhook** (a payment marks the order paid, and refunds and disputes made at Tebex are recorded). Still to come, each as its own change: an **embedded checkout** (Tebex.js: the buyer pays without leaving the shop), recurring payments and entitlements, and the experimental Checkout API mode. **Not checked against a real Tebex store yet** (M2b, before a release candidate): see [Checked against a real store](#checked-against-a-real-store) for what the webhook assumes. See [the roadmap](ROADMAP-v1.0.0.md) (M2).

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
5. **Set up the webhook** (without it an order paid at Tebex is never marked paid, so Tebex is not offered as ready): see [Payments: the webhook](#payments-the-webhook).

Tebex has **no sandbox**. To try a purchase later you switch the store to *Test Mode* in the Tebex panel (Settings → Checkout), which adds a payment method that always succeeds and fires real webhooks. Turn it off, and remove the test payments, before the store is public.

## Selling through Tebex

When Tebex is the store's payment provider (System settings, or `COMMERCE_PROVIDER=tebex`) the shop sells packages instead of products:

- **`/shop` goes to `/shop/packages`**, the catalogue read from the local copy (never from Tebex while the visitor waits), with search, categories and paging. A package's page shows its pictures and description (HTML from the Tebex panel, **cleaned on the server**: scripts, frames, styles and event handlers are removed, and only web addresses are used for pictures), its price *before tax*, and an Add to cart button.
- **The cart holds packages or products, never both**, and all the packages in it are in one currency (the cart is in their currency, not the shop's). A package that cannot be bought more than once is held to one, and a per-customer limit Tebex sets is kept to, whether the package is added again or the quantity is edited (the cart's quantity box stops at the line's own limit). The provider can change while a cart waits, so a cart left over from the other kind refuses what is added to it (products into a cart of packages, packages into a cart of products) and is refused at checkout, with a message each time. When a guest signs in and their cart cannot share a cart with the account's (products against packages, or packages in another currency), the account's cart stays as it was, what the guest had added is left out and the shopper is told so.
- **What the shop does not do for packages.** Tebex is the merchant of record, so it works out the tax, applies its own coupons, creator codes and gift cards and takes the payment. The shop's own discount codes, gift cards, shipping and tax are not used with packages, and the cart and checkout pages say so. No address is asked for. The order carries the shop's quote (each package at the price before tax Tebex quoted when the catalogue was last copied, times the quantity, so the grand total is that sum); what the buyer really pays is Tebex's, and the payment records what Tebex quoted for the basket (`payments.raw.quoted`), and whether it differed from the shop's quote (`price_differs`, also logged as a warning).
- **Not sold from here yet:** a package that needs the buyer to choose something (a server, a rank: Tebex `variables`), a free package (Tebex takes no payment for it, so nothing would tell the shop it was bought), a recurring package's renewals and entitlements (the next slices: its first payment is settled, nothing after it), and a store set up for a platform the shop does not know (see [What buyers are asked](#what-buyers-are-asked)). The storefront shows why a package cannot be bought instead of an Add to cart button.

### What buyers are asked

A Tebex store is set up for a **platform**, and the platform decides what the buyer has to do before they can pay. The shop reads it from Tebex (the list is the one Tebex's own PHP SDK uses) and takes the buyer through whichever it is:

| Platform | What the buyer does |
|----------|---------------------|
| **Universal (No Auth)** | Nothing: the shop makes the basket and sends them to pay. |
| **Minecraft** (Java, Bedrock, Offline/Geyser) and **Overwolf** | **Types a username** on the checkout page ("Minecraft username"). It goes into the basket when it is made, and Tebex answers with the account it recognised. If it does not recognise it, the buyer is told on the form (the order is cancelled and nothing is put in a basket). |
| **Discord**, and the **Steam-based games**: FiveM, RedM, GTA V, Rust, ARK, Garry's Mod, Counter-Strike, 7 Days to Die, Unturned, Conan Exiles, Hurtworld, Space Engineers, ATLAS, Onset, LEAP, CREY, Team Fortress 2 | **Signs in through Tebex** (see below). |
| Anything else (CurseForge, a game Tebex adds later) | The shop does not know how to take them through, so it **does not sell**: see below. |

- What Tebex says about the store (its name, currency and platform) is **remembered whenever Tebex is asked**: by every catalogue synchronisation and by **Test connection**. Nothing waits for Tebex to answer it afterwards, and the ACP page says what buyers of the store are asked.
- For a platform the shop does not know: the ACP page says so, the storefront shows every package as not for sale with that reason, **Add to cart refuses**, the cart says checkout is not available, Tebex does not show as ready in System settings, and **no basket is made** even if a stale form posts one.
- Until the store has been looked at once (an installation that synchronised before this existed, until its next scheduled synchronisation or a click on Test connection) the platform is unknown and treated as one that asks nothing; so is a store that does not say. When the platform is changed in the Tebex panel, the next synchronisation picks it up. A synchronisation that fails keeps what the shop knew.

#### Signing in

Tebex will not take a package into a basket for these stores until the buyer has signed in to it, so checkout works in two steps:

1. The buyer presses **Continue to Tebex** on the checkout page. The shop places the order and makes the basket (without packages), and sends the buyer to **its own link** (`/checkout/tebex/{order}`, signed, so a guest can use it).
2. That link asks Tebex where the buyer can sign in for this basket (`GET /baskets/{ident}/auth?returnUrl=`, with the same link as the place to come back to) and sends them there. If Tebex offers several ways (Steam, Cfx.re...), the buyer chooses on a page of the shop's. When they are back, the link sees that the basket belongs to someone, puts the packages in (with the buyer's Tebex id), and sends them to pay.

Every visit to the link works out how far the buyer has got **from the basket itself**, so it is safe to open twice, to come back to after an hour, or to be sent to again after abandoning the sign-in. A basket that is paid goes to the order page; an order that has expired or been cancelled goes back to the cart; Tebex being unreachable gives a "try again" message and leaves the order as it was. Starting again from the same checkout comes back to the same link.

**Only one request at a time works on a basket.** Adding a package at Tebex adds to what the basket holds, so two visits to the link at once (two tabs, a double click) would both read it as empty and both fill it, and the buyer would be sent to pay for every package twice. The shop takes a lock on the basket (`tebex:basket:{ident}`, in the cache, so it needs a cache that supports locks, as the checkout's own lock does) from reading the basket to filling it, and a visit that has to wait then reads what the first did and finds nothing left to add. One that still cannot get the basket after `TEBEX_BASKET_LOCK_WAIT` seconds (default 10) is sent back to the cart with "already being prepared" and has touched nothing. Closing a checkout (starting again, or running out of time) takes the same lock, so packages cannot be put back into a basket that has just been emptied. Anything else Tebex answers that the shop will not sell on (a basket with no place to pay, one priced in another currency) also sends the buyer back to the cart with a message, and is reported to the log.

After either kind, the payment keeps who Tebex says the buyer is (`payments.raw.buyer`: the name and Tebex's id), the customer's order page says "Delivered to" the name, and staff see the name and the id on the order.

**What is not confirmed yet (M2b, against a real store):** that the sign-in links come back as the documented list (it is read leniently), that `variable_data.username_id` is what a package needs (Tebex names it for packages that run a command on a game server, and the catalogue does not say which are those, so every package carries it), whether FiveM offers more than one way to sign in, and how long a basket stays signed in. A Steam-based store could also be given the buyer's Steam id directly (the SDK has a `user_id` parameter that the OpenAPI schema does not document), so that a buyer who signed in to the shop with Steam is not asked again: not built, and not worth building before the documented way has been seen to work.
### What checkout does

1. The cart is priced again from the copy, strictly: a package Tebex no longer lists, one that has expired or gained choices, a quantity beyond what it allows, or a free one stops the order before Tebex is asked anything.
2. An order is placed (pending, with the packages as its lines, `payment_provider = tebex`). Stock does not come into it.
3. A **basket** is made at Tebex: where the buyer comes back to (a signed link to the order page, and the cart for "cancel"), the order's public id and a random **nonce** (kept on the payment) as the basket's `custom` data, and the buyer's address (so Tebex works out their tax). Each package is added by its `ident`. The buyer is sent to the basket's checkout link.
4. The payment is recorded as pending, under the basket's identifier.
5. **Coming back from Tebex settles nothing.** The order page says it is waiting for the provider to confirm, and only Tebex's webhook marks an order paid (next section).

A call that changes something at Tebex (making a basket, adding a package) is not repeated after a failure that may have got through, only after a 429, since doing it twice would put two of a package in a basket. If any step fails the order is cancelled and the buyer told to try again; a half-made basket at Tebex is harmless.

### Starting again, and running out of time

Tebex has no call that cancels a basket, so a checkout the shop is done with (the buyer starts again, or the order's window passes) is closed by **taking the packages out of it**: a basket with nothing in it has nothing to pay for. The shop asks Tebex for the basket, and only calls it closed if, after taking everything out, Tebex says it is empty and unpaid. A basket that is already paid is not touched, and is reported as not settled, so the buyer is told their earlier payment is still being confirmed rather than being offered a second basket to pay. If Tebex cannot be asked, or will not empty the basket, nothing is replaced.

## Payments: the webhook

Tebex does not report a payment when asked; it **tells** the shop, by webhook. The shop marks an order paid from nothing else: not from the buyer coming back (anyone can open that link), and not from Tebex.js in the browser (Tebex itself says not to trust its events). So **the shop is not ready to sell through Tebex until the webhook has a secret**, and the ACP page, System settings and checkout say so.

### Setting it up

1. In the Tebex panel open **Developers → Webhooks → Endpoints**, add an endpoint at `https://your-shop.example/webhooks/tebex` (the ACP page shows the exact address) and tick the payment, dispute and recurring payment events. The address must be reachable from the internet; a shop on your own machine needs a tunnel.
2. Tebex shows the endpoint's **secret** once. Put it in `.env` as `TEBEX_WEBHOOK_SECRET`, and clear the config cache.
3. Press **Validate** next to the endpoint. Tebex sends nothing else until it is validated: the first delivery is a `validation.webhook`, which the shop answers with its own id, as Tebex asks.

### Who may send

Two checks, both Tebex's own advice. It sends **only from two addresses** (18.209.80.3 and 54.87.231.232); a request from anywhere else is told the page does not exist (404). If a proxy or CDN hides the sender's address and cannot be told to pass it on, `TEBEX_WEBHOOK_CHECK_IP=false` switches that check off (the signature still holds). And every delivery is **signed**: `X-Signature` is an HMAC-SHA256, keyed with the secret, of the SHA-256 hash (as hex) of the body **exactly as it was sent**. It is compared in constant time, and a body that was parsed and written out again does not match. A delivery that is not signed correctly, or arrives while no secret is set, is refused and kept nowhere.

### What each delivery does

Every delivery is kept (in the same archive as Stripe's, under **Billing → Webhooks**, filterable by sender) under Tebex's own id for it, so a delivery that is sent again changes nothing, and one that failed is retried by Tebex (anything but a 2xx makes Tebex send it later) or by **Replay webhook**. The buyer's email, name, address and IP are removed before it is kept, and so is any gift card number; the game account stays, because the order page says who a package was delivered to.

| Delivery | What the shop does |
|----------|--------------------|
| `payment.completed` | Finds the order it is for (below). Marks it **paid**, records the Tebex transaction id and what the buyer paid, takes what was bought out of the cart, and **completes** the order: Tebex delivers its packages itself, so there is nothing to ship. The buyer's game account in the payment becomes the one Tebex says. A payment that arrives after the order was cancelled or expired (the buyer paid at the last moment) is honoured and flagged, like a late Stripe payment. |
| `payment.declined` | Notes the declined attempt on the order for staff. The order stays as it is: the buyer can try again in the same basket, and it expires if nobody pays. |
| `payment.refunded` | A refund made in the Tebex panel. It is recorded as a refund of the order (full, since Tebex refunds a payment in full; a pending refund stays pending until Tebex says it went through), so the order says refunded and its downloads end. If the order already shows it refunded in full (staff recorded it by hand too) nothing is added twice. |
| `payment.dispute.opened` / `.won` / `.closed` | A note on the order and, when opened, a warning in the log. Staff answer a dispute in the Tebex panel. |
| `payment.dispute.lost` | A note, and the money is gone: recorded as a refund. |
| `recurring-payment.*` and anything else | Kept and acknowledged, not acted on yet (recurring payments are a later slice). |

The shop cannot ask Tebex to refund: a refund is made in the Tebex panel (or recorded by hand in the shop if the money went back another way), and arrives here.

### Finding the order

Matching a payment to an order is the one thing that is never guessed.

- The first delivery for a basket is matched by the **custom data** the shop gave the basket: the order's public id and a random nonce kept on that order's payment. Only the shop and Tebex know the nonce, so the order id alone is not enough, and a delivery with the wrong nonce pays nothing.
- Once a payment is recorded under Tebex's transaction id (`tbx-...`), a later delivery about it (a refund, a dispute) is found by that id.
- A delivery that matches nothing **changes nothing**. If it names an order and nonce that do not match, that is flagged (a critical log line and a note on the delivery, which shows as **Needs a look** in the webhook archive and on the Tebex page): money was taken for something the shop cannot find. If it names no order at all, it is noted too, because a store that also sells directly in Tebex (not through the shop) gets such payments, but so would a shop where the custom data does not arrive: see below.

### What was paid

Tebex is the merchant of record: it adds the tax and takes its own codes, creator codes and gift cards, so the amount can differ from the shop's quote (each package at its price before tax). When a payment is reported **the order is made to say what was charged**: more than quoted is recorded as tax, less as a discount, the total becomes the amount paid, and an event on the order says what the shop had quoted. The currency cannot differ (the basket is checked to be in the order's), so a payment in another currency, or with no amount, is **not applied**: the payment is put in review for a person, with a critical log line and a note on the delivery. The same happens to a payment for other packages than the order's (below).

### What was bought

Finding the order is not enough to fulfil it. A basket can be changed by anyone who has its identifier (it is in the address the buyer pays at, and Tebex's Headless API works on a store's public token), so a buyer could swap a cheaper package into the basket after the shop filled it, pay, and still carry the order's id and nonce. So **the packages the payment lists must be exactly the packages the order holds**: the same Tebex package ids, each with the same total quantity (the order of the list does not matter, ids may be text, and a package listed twice adds up). Anything else (a different package, more or fewer of one, an extra, none listed, an entry the shop cannot read, an order line whose package the shop no longer has a copy of) puts the payment **in review**: the order stays unpaid and the cart is left alone, the payment keeps what Tebex listed (`payments.raw.purchased`), and the delivery is marked **Needs a look** with a critical log line. A person decides, in the Tebex panel (refund it) or by hand. The amount is not compared with the shop's quote, because Tebex's tax and its own codes legitimately change it; it is the packages that say what was bought.

Payments the shop did not start are not touched. **A second payment for a basket that is already settled** (a renewal of a recurring package, or a buyer who paid twice) is flagged and never applied: the order is not applied twice and the payment keeps describing the first.

### Staff

The Tebex page in the ACP shows the endpoint, whether the secret is set, whether the sender check is on, when the last delivery came and how many need a look, with a link to them. A delivery that needs a look has its note on its page. **Replay webhook** applies a stored delivery again; for one that was already applied it says so and does nothing.

## Checked against a real store

The webhook is tested against deliveries written from Tebex's documentation, which is not the same as seeing Tebex send one. Before a release candidate the live check (M2b, against a store in Test Mode) must confirm, and the shop should be adjusted where it differs:

- **Where the basket's custom data comes back.** Tebex's documentation says a basket's `custom` object is "included with webhook responses" but its example payment only has a `custom` on each product. The shop looks at the top of the payment and at each product, and uses whichever holds its order and nonce. If neither does, every payment would arrive as "names no order" and no order would ever be settled, which is why that note exists on the delivery. The fallback would be to ask Tebex for the basket.
- **That a payment's `products[].id` is the package id the catalogue gives** (the id the shop keeps as `tebex_id`) and `quantity` the number bought. The shop holds any payment whose products differ from the order's, so if Tebex numbers them differently every payment would be held for review rather than settled: safe, but nothing would sell.
- That `price_paid` is what the buyer paid including tax and after codes, that a refund is always of the whole payment, the shape of a dispute's subject (the documentation gives none; the shop looks for a payment in it), and that `basket.abandoned` (an event the SDK knows and the documentation does not) carries nothing the shop needs.
- That the validation delivery is signed (the shop requires it).

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
| `TEBEX_BASKET_LOCK_WAIT` | `10` | Seconds a request waits for another to finish with a basket before giving up. |
| `TEBEX_SYNC_EVERY_MINUTES` | `60` | How often the scheduler refreshes the copy; `0` is never. |
| `TEBEX_WEBHOOK_SECRET` | none | The secret of the webhook endpoint added in the Tebex panel. Tebex is not ready to sell without it. |
| `TEBEX_WEBHOOK_CHECK_IP` | `true` | Answer only Tebex's two addresses. Set `false` only when a proxy hides the sender's address. |

## Permissions

The page needs `commerce.acp.view`. **Test connection** and **Refresh catalogue** each call Tebex, so they need `commerce.acp.edit` and are rate limited (10 and 6 a minute). No new permission, so no seeder to run again.

## Troubleshooting

| What you see | What it means |
|--------------|---------------|
| "Tebex is not set up" | `TEBEX_PUBLIC_TOKEN` is empty. Set it and clear the config cache (`php artisan config:clear`). |
| "Tebex answered 404: it does not know this store" | The public token is wrong or belongs to a deleted store. |
| "Tebex answered 401/403: it refused the credentials" | The token or key was rotated or revoked in the Tebex panel. |
| "Tebex answered 5xx" or "could not be reached" | Tebex or the network is down; it was already retried. The next scheduled run tries again, or press the button. |
| "This Tebex store is set up for ..., a platform the shop does not know how to take a buyer through yet" | The store's platform is not one the shop knows (CurseForge, or a game Tebex added after this was written). Checking again after changing the platform in the Tebex panel: **Test connection** or **Refresh catalogue**. |
| "Tebex did not recognise that Minecraft username" | The buyer typed a name Tebex has no account for. They correct it on the form. |
| The buyer is sent back to the cart with "Tebex did not offer a way to sign in" | Tebex returned no sign-in links for the basket. Check the store's platform and that sign-in is enabled for it in the Tebex panel. |
| The buyer is sent back to the cart with "already being prepared" | Another request (a second tab, a double click) still had the basket after `TEBEX_BASKET_LOCK_WAIT` seconds, or died holding it (the lock lapses after two minutes). Trying again is safe. |
| The buyer is sent back to the cart with "We could not start the payment" | Tebex answered with a basket the shop will not sell on: no place to pay, or priced in another currency than the order's (the log has which). Starting the checkout again makes a new basket. |
| "What is in your cart is not sold the way this shop takes payment now" | The store's provider changed while a cart of the other kind was waiting. Empty it and add something again. |
| Tebex is "Not set up" under Payments, or not offered as ready | `TEBEX_WEBHOOK_SECRET` is empty (or the config is cached). The shop would never see a payment, so it will not sell. |
| A buyer paid and the order is still pending | No delivery has come (the endpoint is not validated, not reachable from the internet, or a firewall blocks Tebex's addresses), or it came and is marked **Needs a look**. See the archive under Billing → Webhooks, filtered to Tebex. |
| Deliveries are refused with 404 | They arrive from an address that is not Tebex's (a proxy or CDN in front). Make the proxy pass the real address, or set `TEBEX_WEBHOOK_CHECK_IP=false`. |
| Deliveries are refused with 400 | The secret is not the endpoint's, or something changed the body before it got here (a proxy that rewrites JSON). |
| "names no order" on a payment | The payment carries none of the shop's custom data. Either it was a sale made outside the shop, or Tebex does not return the data where the shop looks: see [Checked against a real store](#checked-against-a-real-store). |
| "Tebex priced order ... in EUR, not USD" | The basket came back in another currency than the packages were copied in. Refresh the catalogue; if it persists the store's currency changed in the Tebex panel. |
| "Tebex quoted a different price than the shop did" (in the log) | The copy is out of date: Tebex's price moved since the last synchronisation. The buyer paid what Tebex charged; refresh the catalogue. |
| "A synchronisation is already running" | Another run holds the lock (it is released when the run ends, or after five minutes). |
| "Tebex sent a package (or category, or list of packages) the shop could not understand" | An entry had no id, was not an object, or the list was not a list. Nothing was changed. Please report it with the response, since it means the shop's idea of Tebex's format is out of date. |
| The page is empty after a successful run | The store has no categories, or its packages are not in any category Tebex lists. |
| Prices differ from the Tebex store page | Tebex works out sales tax for the buyer's country. The copy has the price before tax, and the tax and total as Tebex quoted them to the shop's own request, which need not be the buyer's. |

## Why not the official SDK

Tebex publishes a PHP SDK (`tebex/tebex-sdk-php`, MIT, PHP 7.4+, Guzzle). The shop has its own small client (`TebexClient`) over Laravel's HTTP layer instead, on purpose: it can be tested with `Http::fake()` like everything else, it has one place that decides what is repeated after a failure (a call that changes something is not, after anything but a 429), and it takes the store's token out of every message. The SDK is still useful as Tebex's own reference: reading its source settled which platform needs no basket authorisation, confirmed the add-package path, and revealed two webhook events the documentation does not list (`basket.abandoned`, `recurring-payment.status-changed`). Its webhook parsing is not used because it reads the request itself (and appears to fail when it does), compares the signature with `strcmp` rather than a constant-time comparison, and keeps the secret in a static property, none of which suits a queue-processed, replay-safe receiver.

## Testing

The whole thing is tested without a network: `Http::fake()` answers with the files in `tests/Fixtures/tebex`, which were written from Tebex's published [OpenAPI schema](https://github.com/tebexio/TebexHeadless-OpenAPI) and [webhook documentation](https://docs.tebex.io/developers/webhooks/overview). `Tests\Support\InteractsWithTebex` sets it up, and `InteractsWithTebexWebhooks` signs and delivers webhooks from Tebex's address (`tests/Fixtures/tebex/payment.json` is the documentation's example payment). A fixture cannot prove that real Tebex sends exactly that, which is why the milestone has a live check (M2b) that compares the fixtures with a real store before a release candidate.

## Sources

- Headless API: <https://docs.tebex.io/developers/headless-api/introduction>
- Endpoints: <https://docs.tebex.io/developers/headless-api/endpoints>
- Webhooks: <https://docs.tebex.io/developers/webhooks/overview>
- Testing: <https://docs.tebex.io/developers/headless-api/testing>
