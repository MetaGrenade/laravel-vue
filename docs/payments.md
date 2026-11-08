# Payments

How to take money with MetaForger: choosing between Stripe and Tebex, setting each one up, trying a purchase before a real customer makes one, and going live. This page is the path through. The details of each piece stay in the pages it links to: [commerce.md](commerce.md) for orders and refunds, [billing.md](billing.md) for subscriptions, [tebex.md](tebex.md) for Tebex, [webhooks.md](webhooks.md) for how a provider's messages are kept, and [configuration.md](configuration.md) for every setting.

> **What has and has not been tried.** Both providers are built and tested without the real service: Stripe through its own PHP library with scripted replies and signed events written by the tests, Tebex through deliveries written from its documentation. That proves the shop does what it means to do with those messages. It does not prove that Stripe or Tebex sends exactly them. **A purchase and a refund in Stripe's test mode, and the check of the Tebex webhook against a real store in Test Mode (the roadmap's M2b), are still to be done before a release candidate.** Do both on your own site before you take real money; [Try it before anyone real does](#try-it-before-anyone-real-does) is how. The parts of Tebex that have never met a real account are listed in [tebex.md](tebex.md#checked-against-a-real-store).

## Which one

You can set both up. The shop uses **one at a time** for its orders: `COMMERCE_PROVIDER` (default `stripe`), or the choice under **System settings → Payment Provider** in the admin area, which wins. Every order remembers the provider it was placed with and is settled with that one, so switching never strands a payment in flight.

**Subscriptions to a plan are always Stripe** (Laravel Cashier), whichever provider the shop's orders use. A site can sell plans through Stripe and packages through Tebex.

| | Stripe | Tebex |
|---|---|---|
| What it is | A payment processor. You are the seller. | A merchant of record. Tebex sells to the buyer, works out the tax and handles chargebacks. |
| What the shop sells | The shop's own products: variants, stock, shipping, downloads, licence keys. Plus subscription plans. | Tebex's **packages**: the catalogue is kept in the Tebex panel and copied into the shop. One-off or recurring. (Experimental: the shop's own products through Tebex's Checkout API.) |
| Prices, tax, discounts | The shop's: tax rates, shipping zones, discount codes and gift cards. | Tebex's. The shop's discount codes, gift cards, shipping and tax are **not used** for packages. |
| Where the buyer pays | Stripe's hosted Checkout page. | Tebex's hosted page, or Tebex.js on a page of the shop's (off by default). |
| Refunds | From the order page, in full or in part. | In the **Tebex panel**; the shop records them when Tebex says so. (Checkout API: from the order page, a whole payment at a time.) |
| Disputes | In the Stripe Dashboard. The shop is not told about them. | Recorded on the order; a lost dispute is recorded as a refund. |
| Trying it | Stripe test mode, with keys of its own. | Tebex **Test Mode** in the panel. There is no sandbox. |
| The shop is told by | `POST /stripe/webhook` | `POST /webhooks/tebex` |
| Offered at checkout when | `STRIPE_SECRET` and a webhook secret are set. | The public token and `TEBEX_WEBHOOK_SECRET` are set, and the store's platform is one the shop knows. |

If you are building a game server or community shop, Tebex is usually the one: the game platforms, the delivery in the game and the tax are theirs. For a shop that sells your own products, downloads, licence keys or goods you ship, or for plans, it is Stripe. You can switch later, but a cart left over from the other kind is refused at checkout: a cart holds Tebex packages or the shop's products, never both.

## What both need

- **A public address on https that the provider can reach.** Stripe and Tebex send their messages to your site, so a site on your own machine needs a tunnel, or for Stripe the CLI (below). See [deployment.md](deployment.md).
- **A queue worker and the scheduler**, both running. The scheduler expires unpaid orders and gives their stock back (every five minutes), and the receipts, refund notices and payment emails go through the queue. [deployment.md](deployment.md#what-a-live-site-is-made-of) says how to run them.
- **`TRUSTED_PROXIES`, if a proxy or a load balancer ends https in front of the site.** The link a buyer comes back by after paying is signed, and a signed link fails behind a proxy that Laravel has not been told to believe. See [deployment.md](deployment.md#behind-a-proxy-or-a-load-balancer).
- **Mail that works.** The customer is emailed when an order is paid or refunded, and when a renewal fails. See [email.md](email.md).
- **A currency.** Set `COMMERCE_CURRENCY` (it follows `CASHIER_CURRENCY` when unset) **before the first price is entered**. The shop sells in one currency. With Tebex's Checkout API it has to be the store's.

Each provider's webhook endpoint is kept registered even when the shop section is switched off in the admin area, so a payment in flight still settles.

## Stripe

### 1. Keys

In the Stripe Dashboard stay in **test mode** (Stripe's *Test mode* switch) until [Going live](#going-live). Under **Developers → API keys** copy the publishable key (`pk_test_...`) and the secret key (`sk_test_...`) into `.env`:

```
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
```

Clear the config cache (`php artisan config:clear`) if the site caches its configuration. Stripe moves its menus about from time to time; the words to look for are *API keys* and *Webhooks*.

### 2. The webhook

The shop never marks an order paid because a buyer came back to its page; it waits for Stripe to say so. One endpoint serves shop orders and subscriptions.

**On your own machine,** forward Stripe's events with the [Stripe CLI](https://docs.stripe.com/stripe-cli):

```bash
stripe listen --forward-to http://localhost:8000/stripe/webhook
```

It prints a signing secret (`whsec_...`). Put it in `.env` as `STRIPE_CLI_WEBHOOK_SECRET` and leave `STRIPE_WEBHOOK_SECRET` for a real endpoint. **Leave `STRIPE_CLI_WEBHOOK_SECRET` empty on a live site:** anybody who has run the CLI knows a secret that would sign a payment.

**On a real site,** add an endpoint in the Dashboard (**Developers → Webhooks**) at `https://your-site.example/stripe/webhook`, tick the events below, and copy the endpoint's signing secret into `.env` as `STRIPE_WEBHOOK_SECRET`.

| Events | For |
|---|---|
| `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired` | A shop order being paid, paid late (a bank debit), failing or running out. |
| `refund.created`, `refund.updated`, `refund.failed` | A refund that is pending, fails later or is made in the Dashboard. |
| `charge.refunded` | Optional: a second signal that something was refunded. |
| `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted` | A subscription following Stripe (Cashier keeps the local copy in step). |
| `customer.subscription.trial_will_end` | The reminder before a free trial ends. |
| `invoice.payment_succeeded`, `invoice.payment_failed` | Invoices, and the emails for a failed payment and for one that was put right. |

The first three rows are for the shop's orders and the last three for plans: tick only the ones you use. A refund for a payment the shop did not take (a subscription invoice) is ignored.

A signature more than `STRIPE_WEBHOOK_TOLERANCE` seconds (300) away from the server's clock is refused, so a server whose clock is wrong refuses everything: keep it on network time.

### 3. Choose it, and check

Open **System settings → Payment Provider** in the admin area. Stripe says **Configured** when the secret key and a webhook secret are both set. If it says *Missing credentials*, that is what is missing: with no secret a payment could never be confirmed, so the shop will not offer it.

### 4. Plans (only if you sell subscriptions)

1. In Stripe make a **product** with a **recurring price** for each plan, in the same mode as your keys. Copy each `price_...` id.
2. In the admin area open **Billing → Plans** and make or edit the plan with that price id. (The two example plans, Starter and Pro, read `STRIPE_PRICE_STARTER` and `STRIPE_PRICE_PRO`, which are placeholders until you make the prices.) What a plan gives an account is set there too: [entitlements.md](entitlements.md#subscription-plans).
3. Save the **customer portal** settings in the Dashboard (**Settings → Billing → Customer portal**) **in each mode**, or the *Manage billing in Stripe* button cannot open it ([billing.md](billing.md#customer-portal)).
4. In **Settings → Billing → Revenue recovery** choose how many times Stripe retries a failed renewal and what it does when it gives up. The shop follows Stripe's decision and emails the customer ([billing.md](billing.md#when-a-payment-fails)).

Free trials and promotion codes are in [billing.md](billing.md#free-trials); a promotion code is made in Stripe and lives only there.

### 5. Refunds

On the order page in the admin area, **Refund** sends money back through Stripe, in full or in part (it needs the `commerce.acp.refund` permission, which only the `admin` role has until you give it to another role under **Access control**). A refund that is pending, or whose answer was lost, is settled with **Check status**. A refund made in the Stripe Dashboard is picked up from the webhook. How a refund runs, and what it does to stock and downloads, is in [commerce.md](commerce.md#refunds).

### What the shop checks for you

- The amount and currency Stripe reports are compared with the order **before** it is marked paid. A mismatch puts the payment in *review*, writes a `critical` line to the log and does not fulfil the order.
- Only a session the shop made for one of its orders is acted on, and the return link is signed.
- A delivery Stripe sends twice changes nothing the second time ([webhooks.md](webhooks.md)).

More in [commerce.md](commerce.md#stripe).

## Tebex

The long version is [tebex.md](tebex.md). The path:

1. **Have a Tebex store** whose platform the shop knows (Minecraft, FiveM, Rust, Discord and the other Steam-based games, Overwolf, Universal), with packages in it. The catalogue is **only** edited in the Tebex panel; nothing in the shop's product pages applies to it.
2. **Keys.** In the Tebex panel (**Developers → API Keys**) copy the public token and the private key into `.env` as `TEBEX_PUBLIC_TOKEN` and `TEBEX_PRIVATE_KEY`.
3. **Copy the catalogue:** `php artisan tebex:sync`, or **Refresh catalogue** under **Commerce → Tebex** in the admin area. Press **Test connection** there. The scheduler then keeps the copy fresh every `TEBEX_SYNC_EVERY_MINUTES` minutes.
4. **The webhook. This is the step that is easy to forget, and without it nothing is ever marked paid.** In the Tebex panel (**Developers → Webhooks → Endpoints**) add `https://your-site.example/webhooks/tebex` (the Tebex page in the admin area shows the exact address), tick the payment, dispute and recurring payment events, copy the secret Tebex shows **once** into `.env` as `TEBEX_WEBHOOK_SECRET`, clear the config cache, and press **Validate**. The shop answers Tebex's validation delivery with its own id. Until the secret is set Tebex is not offered as ready, on purpose.
5. **Choose it** under **System settings → Payment Provider** (or `COMMERCE_PROVIDER=tebex`). `/shop` then goes to the packages.

Things to know before you choose it:

- Tebex only sends from two addresses, which the shop checks (any other sender is told the page does not exist). A proxy or CDN that hides the sender's address needs to pass it on, or `TEBEX_WEBHOOK_CHECK_IP=false` turns that one check off; the signature is still checked ([tebex.md](tebex.md#who-may-send)).
- A payment is matched to its order by data the shop put on the basket. A payment for different packages than the order holds is put in **review** for a person, not fulfilled.
- Tebex adds the tax and its own codes, so what the buyer paid can differ from the shop's quote; the order is made to say what was really charged.
- A package that needs the buyer to choose something (a server, a rank), a free package, and a package that gives a role, a badge or a download are not sold from the shop ([tebex.md](tebex.md#selling-through-tebex)).
- The shop cannot refund through Tebex's Headless API (the default mode). Refund in the Tebex panel and the shop records it from the webhook.
- Recurring packages are charged, retried and ended by Tebex; the shop records each renewal and where the recurring payment stands, and cannot cancel one ([tebex.md](tebex.md#recurring-payments)).
- Where the buyer pays: `TEBEX_CHECKOUT_MODE` is `redirect` by default (Tebex's page). `popup` and `inline` use Tebex.js on the shop's page and have not met a real store ([tebex.md](tebex.md#paying-on-the-shops-own-page-tebexjs)).
- **The Checkout API mode** (`TEBEX_CHECKOUT_API_ENABLED`, to sell the shop's own products through Tebex) is experimental, needs Tebex's approval and has never run against a live account. Leave it off unless you are testing it with them ([tebex.md](tebex.md#selling-the-shops-own-products-the-checkout-api-experimental)).

## Try it before anyone real does

Do all of this on the site you will launch from, or on a copy of it, with test money. Watch **Billing → Webhooks** in the admin area as you go: every delivery from both providers is kept there, and one the shop understood but could not act on is marked **Needs a look**.

### Stripe, in test mode

1. Make a cheap product. Buy it with Stripe's test card `4242 4242 4242 4242` (any future expiry, any CVC), once as a guest and once signed in. Expect: you are sent to Stripe's page and back to the order page, which says paid within seconds; the receipt email arrives (the queue is running); the stock went down; the webhook archive shows `checkout.session.completed`; the order page in the admin area links to the payment in Stripe.
2. Pay with a card that Stripe declines. Expect: the order stays unpaid and you can try again. Leave one unpaid and see that it is released after `COMMERCE_PAYMENT_WINDOW` minutes (60 by default; the scheduler does it).
3. Pay with a card that asks for 3-D Secure, and finish the step.
4. **Refund part of an order, then the rest,** from the order page. Expect the refund to show as succeeded, the order to say *partially refunded* and then *refunded*, the customer to be emailed, and Stripe's Dashboard to show it. Then refund an order **in the Dashboard** and see the shop pick it up.
5. If you sell plans: subscribe, check that the plan and what it gives are live; cancel; subscribe with a card that is accepted when saved and refused when charged, and check the failed-payment email and the *paused* state ([billing.md](billing.md#when-a-payment-fails)).

Stripe's list of test cards is at <https://docs.stripe.com/testing>; use it rather than this page for the numbers, which Stripe may change.

### Tebex, in Test Mode

1. Turn on **Test Mode** in the Tebex panel (**Settings → Checkout**). It adds a payment method that always succeeds and sends real webhooks.
2. Buy a package from the shop. Expect: Tebex's page (or the popup), then the order page. The order is marked paid **by the webhook**, so look for a `payment.completed` delivery under Billing → Webhooks, filtered to Tebex. If it says **Needs a look**, the note says why.
3. Check what the buyer should hold afterwards: the feature or limit set on the package ([entitlements.md](entitlements.md)), if any.
4. Refund the payment in the Tebex panel and see the order say refunded.
5. **Turn Test Mode off and remove the test payments in Tebex before the store is public.**

If a payment in step 2 arrives and the shop says it "names no order," or every payment is held for review, that is exactly what the live check exists to find: open an issue with what Tebex sent, without the buyer's details ([tebex.md](tebex.md#checked-against-a-real-store)).

## Going live

Work through this with the keys of the live mode in a copy of `.env` before you switch. Everything in [configuration.md](configuration.md#before-a-site-goes-live) applies as well.

**Both**

- [ ] The site is on https at its public address, `APP_URL` is that address, and `TRUSTED_PROXIES` is set if a proxy ends https.
- [ ] A queue worker and the scheduler run, and restart after a deploy ([deployment.md](deployment.md#every-deploy)).
- [ ] `COMMERCE_CURRENCY` is right, and prices were entered after it was set.
- [ ] Mail is sent from a real address and a test email arrives.
- [ ] Taxes: with Stripe, **Tax rates** are entered for where you sell, and **Shipping** if you ship. Digital shops can leave both empty. With Tebex, Tebex works out tax.
- [ ] Your terms and privacy pages say what you sell, how refunds work and what you do with a buyer's details ([legal-pages.md](legal-pages.md)).
- [ ] Staff who should refund have `commerce.acp.refund`, and nobody else.
- [ ] The test orders, test subscriptions and test customers are gone, or you are launching from a database that never had any. A Stripe customer made in test mode does not exist in live mode, so a member whose billing account (Cashier's `stripe_id`) was made in test mode would be refused by Stripe when they subscribe.

**Stripe**

- [ ] The account is activated in the Dashboard (Stripe asks for business details before it takes real money).
- [ ] `STRIPE_KEY` and `STRIPE_SECRET` are the **live** keys (`pk_live_...`, `sk_live_...`).
- [ ] A **new webhook endpoint in live mode** points at `/stripe/webhook`, with the events above, and its signing secret is `STRIPE_WEBHOOK_SECRET`. Test and live endpoints are separate and have separate secrets.
- [ ] `STRIPE_CLI_WEBHOOK_SECRET` is **empty**.
- [ ] If you sell plans: the products and prices exist **in live mode**, and every plan under **Billing → Plans** has its live `price_...` id (the test ids do not exist in live mode). The customer portal and revenue recovery are saved in live mode too.
- [ ] One real purchase of your cheapest product with a real card, then a refund of it from the order page. Do this before you announce anything.

**Tebex**

- [ ] **Test Mode is off** in the Tebex panel and the test payments are removed.
- [ ] `TEBEX_PUBLIC_TOKEN`, `TEBEX_PRIVATE_KEY` and `TEBEX_WEBHOOK_SECRET` are the live store's; the endpoint is validated; the Tebex page in the admin area shows the secret is set and when the last delivery came.
- [ ] The catalogue is synced and the scheduler keeps it so.
- [ ] One real purchase of the cheapest package, and that it was marked paid and delivered.

## After launch

- **Watch the webhook archive** (Billing → Webhooks) for **Needs a look**, and the log for `critical` lines. A payment in *review* is money taken that the shop would not apply: a person decides, in the provider's dashboard or by hand.
- **A payment the buyer made while the webhook was down** is not lost. Stripe retries a delivery for a long while, and **Replay webhook** applies a stored one again. For a Stripe order **Check payment** on the order page asks Stripe what became of it. Tebex's Headless API cannot be asked about a payment, so for Tebex look in the archive and have Tebex send it again.
- **Switching provider** later changes what *new* orders use. Existing orders, refunds and webhooks keep using the provider they were made with.

## When a buyer paid and the order is still pending

| What you see | What it means |
|---|---|
| **Billing → Webhooks** has nothing from that provider | The message never arrived. The endpoint address is wrong or unreachable from the internet (a firewall, a proxy), the events are not ticked, or (Stripe) the endpoint was made in the other mode. The provider's own dashboard shows what it sent and what your site answered. |
| A delivery is there and was refused (400) | The secret is not the endpoint's (Stripe: test and live endpoints have different ones; Tebex: the secret was shown once and mistyped), the config is cached from before it was set, or something between the provider and the site changed the body. For Stripe, also check the server's clock. |
| Tebex deliveries are refused with 404 | They arrive from an address that is not Tebex's. See `TEBEX_WEBHOOK_CHECK_IP` above. |
| A delivery is there and failed (500) | Read the error on the delivery and in the log, fix it, and press **Replay webhook**. Stripe and Tebex also retry. |
| A delivery is there and marked **Needs a look** | The shop understood it and chose not to act: an amount or currency that differs (Stripe), packages that are not the order's, an order it cannot find (Tebex). The note on the delivery says which. |
| The order was paid but the buyer got no email or no download | The queue worker is not running, or the product or package gives nothing. See [commerce.md](commerce.md#digital-goods) and [entitlements.md](entitlements.md#troubleshooting). |
| Stripe says *Missing credentials*, or Tebex says it is not set up | `STRIPE_SECRET` or the webhook secret is empty (Stripe), or `TEBEX_PUBLIC_TOKEN` / `TEBEX_WEBHOOK_SECRET` is (Tebex), or the config is cached. |

Each provider has its own table with more: [billing.md](billing.md#troubleshooting) for subscriptions and [tebex.md](tebex.md#troubleshooting).

## What is not covered

- **Stripe disputes.** The shop is not told about them. Answer them in the Stripe Dashboard, and if the money went, record it on the order with the refund form's *I already returned the money another way*.
- **Other providers.** Adding one means implementing the payment contract; see [commerce.md](commerce.md#payment-providers).
- **Tax and legal advice.** The shop works out tax from the rates you enter, and Tebex works it out for its own sales. Whether they are right for where you sell is for you and an accountant.
- **Stripe's and Tebex's own fees and rules,** including what Tebex allows you to sell.
