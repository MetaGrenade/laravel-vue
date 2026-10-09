# Commerce

How the shop takes an order from a cart to a paid order. This page covers what exists now (milestone M1, first three slices) and how it is built, so you can extend or replace parts of it. Planned follow-ups (refunds and order management, coupons, digital goods, reviews and wishlists) are listed at the end and in [ROADMAP-v1.0.0.md](ROADMAP-v1.0.0.md).

## The flow

```
cart ──► checkout form ──► order placed (pending, stock held) ──► payment provider's page
                                                                        │
              order paid ◄── webhook (or the return page, as a fallback)┘
```

1. **Cart.** Guests have a cart tied to their browser session; signed-in customers have one tied to their account. The cart is only a convenience view: it remembers a price, but nothing that matters is read from it later.
2. **Checkout.** The shopper gives an email, a shipping address (for anything that is shipped), picks a shipping method and sees shipping and tax update as they go. `POST /checkout` places an order. `OrderPricer` looks the product, variant, price, shipping and tax up again and `OrderPlacer` stores the result, so a stale or tampered cart or form cannot buy something at the wrong price, ship somewhere it should not, or skip tax. Stock is held with one conditional `UPDATE` per line, so two shoppers racing for the last unit cannot both win, on any database.
3. **Payment.** The store's payment provider creates a checkout and the customer is sent to the provider's own page. For Stripe this is hosted Stripe Checkout: card details never touch this application.
4. **Confirmation.** The provider calls the webhook, the order becomes paid, and a receipt email is sent. If the customer returns before the webhook arrives, the confirmation page asks the provider directly, so the order is not stuck on "waiting".

## Orders

An order has two independent states.

| `status` | Meaning |
|----------|---------|
| `pending` | Placed, waiting for payment. Stock is held. |
| `processing` | Paid, waiting to be fulfilled. |
| `completed` | Fulfilled (shipped, or delivered if digital). |
| `cancelled` | Cancelled before payment (stock released), or refunded in full before it was fulfilled. |

| `payment_status` | Meaning |
|------------------|---------|
| `unpaid` | No successful payment yet. |
| `paid` | Payment confirmed for the full amount. |
| `failed` | The payment attempt failed for good. |
| `partially_refunded` | Some of the money has been returned (see [Refunds](#refunds)). |
| `refunded` | All of it has been returned. |

A refunded order was still paid for once, so `Order::isPaid()` is true for `paid`, `partially_refunded` and `refunded`: it cannot be paid a second time or cancelled as if it were unpaid.

All state changes go through `App\Support\Commerce\OrderLifecycle` (and, for refunds, `OrderRefunder`). Each method locks the order row, re-reads it and does nothing if the change already happened, so a redelivered webhook or a double click cannot apply a change twice. Events (`OrderPaid`, `OrderCancelled`, `OrderFulfilled`, `OrderRefunded`) are dispatched after the transaction commits; the receipt, shipping and refund emails and a safety-net close of the provider's checkout are listeners on them.

Orders are addressed in URLs by an unguessable `public_id` (a ULID), not the row id. People see the order number (`MF-000123`; the prefix is `COMMERCE_ORDER_PREFIX`).

### One payable checkout per cart

A cart never has two checkouts that can both be paid. When a shopper starts again (they changed their mind, edited the cart, or came back from the provider), `CheckoutStarter`:

1. takes a short per-cart lock, so a double click or two tabs cannot run two checkouts at once;
2. asks the provider to **close** the earlier checkout (`PaymentProvider::closeCheckout()`) and waits for the answer;
3. only then cancels the earlier order, frees its stock and creates the replacement.

The answer decides what happens next. *Closed*: carry on. *Paid*: the earlier checkout had in fact been paid, so the shopper is taken to that order and nothing new is started. *Unresolved* (a bank debit still settling, or a payment held for review) or an error (the provider could not be reached): nothing is replaced and the shopper is asked to try again, because a checkout that stays payable beside its replacement could be paid twice. The queued `ExpireProviderCheckout` listener is only a safety net for other cancellations; this guarantee does not depend on a queue worker.

### The cart after payment

An order is a snapshot of the cart when checkout started. The shopper may keep editing the cart while the payment is pending, so paying removes only what was ordered: a line leaves when its whole quantity was bought and is reduced when the cart held more. Anything added since stays, and the cart is marked converted only once nothing is left in it.

### Who may see an order

Orders and payments carry an owner (`owner_type`, `owner_id`), and `user_id` only records who placed the order. Access goes through `OrderPolicy` and `App\Support\Ownership`, never through `user_id`. In 1.0 a person's only owner is themselves; 1.1 adds teams, and this is the one place that changes. A guest order has no owner and is opened through the signed link in the receipt email.

## Addresses

A signed-in customer has an address book (**Settings → Addresses**, only while the shop section is on). At checkout they can pick a saved address or type one, and tick *Save this address for next time*. Guests just type one.

An order keeps its **own copy** of the shipping and billing address (`orders.shipping_address`, `orders.billing_address`), so editing or deleting a saved address never changes an order that was placed. Saved addresses are owned through `owner_type` / `owner_id` and authorised through `AddressPolicy`, like orders, so a team can have one in 1.1.

Which addresses the form asks for depends on the cart:

- Anything that has to be **shipped** needs a shipping address. A separate billing address is only asked for if the shopper says it differs; otherwise billing is the shipping address.
- A cart of **digital goods** (every product has *Needs shipping* off) is not shipped anywhere, so there is no shipping address. A billing address is asked for only when the shop charges tax by location, because it decides the tax.
- Postal codes are required except in the few countries that have none. A state or province is required where tax depends on it (see below). Country codes are ISO 3166-1 alpha-2.

## Shipping

Shipping is set up with **zones** and **rates** (stored in `shipping_zones` and `shipping_rates`, in the store currency):

- A zone lists the countries it covers, or `*` for everywhere not covered by another zone. A zone that names a country always beats the `*` zone; among zones naming it, the lowest position wins.
- A zone has one or more rates. Each has a price, an optional minimum and maximum order value (only the items that are actually shipped count, so a download cannot help a parcel qualify), and can be switched off. *Free shipping over 100* is a zero-price rate with a minimum of 100.
- The shopper chooses among the rates that apply. One shipping charge is made per order however many items it holds.
- **If no zone is active, shipping is not set up**: orders are accepted for any country with no shipping charge, so you can arrange delivery yourself. As soon as one zone exists, only the countries your zones cover can order physical goods; for other countries checkout says it cannot ship there.

Manage them in the admin area under **Commerce → Shipping** (or the *Shipping zones and rates* button on the Commerce page). Add a zone, choose its countries (one at a time, *Add EU countries* for all 27, or *Everywhere else*), then add its rates. `php artisan db:seed --class=CommerceDemoSeeder` adds example zones, rates and tax to try it out.

Things the screen guards against, because they change what customers can do:

- With **no active zone**, the page says shipping isn't set up and that orders ship anywhere for free. Deleting or deactivating your last active zone puts you back there, and the delete confirmation says so.
- A zone with **no active rates** is flagged: customers in its countries cannot order anything that needs shipping (it does not fall through to another zone).
- *Everywhere else* cannot be combined with named countries in one zone, since it already means "every country not listed in another zone".

Changing or deleting a zone, rate or tax rate never affects orders already placed: an order keeps the shipping method, shipping charge and tax it was charged. A customer who is part-way through checkout and had chosen a rate that has since been deleted is asked to choose again.

Permissions: seeing the pages needs `commerce.acp.view`; adding, editing and deleting need `commerce.acp.create`, `commerce.acp.edit` and `commerce.acp.delete`. Someone with only *view* sees the tables without the buttons.

## Tax

Tax is a configurable table (`tax_rates`). **Prices are tax-exclusive**: tax is added at checkout.

- For a destination, every active rate for that country applies, and a rate with a **region** only when the address is in that region (matched without regard to case). Several rates **add together**, for example a federal and a provincial tax.
- A rate for `*` is the **fallback** for countries (and regions) that have none of their own.
- Tax follows where the goods go: the shipping country, or for digital-only orders the billing country.
- Each rate can apply to shipping or not. A product can be marked **not taxable**.
- Each rate is applied to the taxable base and rounded half up in minor units, then shared between the order lines by largest remainder, so the line amounts always add up to the total exactly. The tax shown to the customer is one line per rate (`VAT (20%)`).
- Where tax depends on the state or province (a rate has a region), checkout requires one.
- Rates are managed under **Commerce → Tax rates**. A rate for *Everywhere else* is the fallback and cannot have a region. With no active rate, no tax is charged, and the page says so.

This suits simple setups. It does not do thresholds, product-category rates, tax-inclusive pricing or B2B reverse charge; for complex jurisdictions, an integration with Stripe Tax is planned as an alternative driver.

## Discount codes

A discount code (`coupons`) is one of three kinds: a **percentage off**, a **fixed amount off** (in the shop's currency), or **free shipping**. Staff manage them under **Commerce → Discount codes**; customers type the code on the cart or checkout page. One code can be on an order.

**What it can be limited by**, all optional: dates (starts, ends), a minimum spend, a total number of uses, a number of uses per customer, and the products and categories it applies to (percentage and fixed codes only; with both empty it applies to everything, otherwise to the products picked plus the products in the categories ticked). The minimum spend is counted on the items the code applies to, before the discount; for free shipping it is the whole cart.

**Where it fits in the price.** The cart is priced as before, shipping is chosen from the cart *before* any discount (a code never changes which rates are offered), then the discount comes off, then tax is charged on what is left:

- Subtotal, plus shipping, plus tax, less the discount, always adds up to the order total. Shipping and the subtotal are shown before the discount; a free-shipping code is part of the discount, so the shipping charge is still shown and the discount takes it off (and the tax on it).
- A percentage is worked out on the eligible items and rounded half up to the cent; a fixed amount is never more than the eligible items cost. Either way the amount is **shared between the eligible lines by largest remainder**, so the lines add up to it exactly, and tax is shared between the taxable lines in proportion to what each costs after the discount.
- A code that would make the **whole order free** (nothing left to pay once shipping and tax are counted) is refused, because a payment provider cannot take a payment of nothing. Free orders arrive with digital goods.

**One definition of "valid".** Putting a code on a cart, the live quote and the order that is placed all run through `DiscountCalculator`, which `OrderPricer` calls every time the cart is priced. A code is accepted by pricing the cart with it exactly as checkout will. It is checked again at checkout, so a code that expired or ran out in the meantime is refused there with a message (and the cart page says why). The code is held on the cart (`carts.coupon_id`), so it works for guests and survives a visit to the payment page; it reserves nothing until an order is placed.

**How uses are counted.** Nothing is stored for it: a use is an order that carries the code and has not been cancelled. An order waiting for payment holds its use until it is paid or expires, so cancelling, expiring or fully refunding an order gives the use back. The shopper's own earlier unpaid order from the same cart does not count against them (the next checkout replaces it). Two checkouts racing for the last use take turns: placing an order locks the coupon row, then counts. *Per customer* means the signed-in account, or the email on the order for a guest (compared without regard to case), so a guest who uses a different email is a different customer. It is checked when the email is known, which for a guest is at checkout.

**What an order records.** `orders.coupon_id` and `coupon_code`, `discount_total` (items and shipping together), each line's `discount_total`, and `metadata.discount` with what the code was worth (`items` and `shipping`). Editing the coupon later never changes an order that was placed. A code that orders have used can be **switched off but not deleted** (the database refuses it too), so its uses stay countable.

**What Stripe is sent.** Checkout takes no negative lines and no fractions of a cent, so each item is sent at what the customer pays for it. When that does not divide evenly between the units (three at 10.00 with 1.00 off is 29.00), the line is split into two whose prices differ by one cent (one at 9.66 and two at 9.67): the quantity is kept and the total is exact. Shipping is sent less any the code waived. The provider still refuses to start a checkout whose lines do not add up to the order total.

**Abuse.** Applying a code is throttled (10 a minute and 60 an hour per account or address, right or wrong), a malformed code is refused without being looked up, and a code that is switched off, not yet started or unknown all give the same answer, so they cannot be told apart.

The demo seeder adds `WELCOME10`, `SAVE5` and `FREESHIP` to try.

## Managing the catalogue

**Commerce → Products** lists everything you sell (search by name, web address or a variant's SKU; filter by status and brand) with its price, stock and whether a shopper could buy it today. A product's own page has everything about it, with a checklist at the top of what is still missing:

| Tab | What it holds |
|-----|---------------|
| Details | Name, web address, description, brand, categories, tags, and the switches *On sale*, *Needs shipping* and *Charge tax*. |
| Price and stock | The product's price, and its stock if it has no variants. |
| Options and variants | Options such as Size and Colour with their values, and the variants made from them, each with its own SKU, price and stock. |

**Brands, categories and tags** are managed on their own page (Commerce → Brands and tags). Deleting one never deletes products: they just lose the brand, or the link.

Each action has its own permission: `commerce.acp.view` to look, `create` to add products, options, variants, prices and stock, `edit` to change them, and `delete` to remove them.

### Prices

The shop sells in one currency (`COMMERCE_CURRENCY`), so a price is always created in it and there is no currency to choose. A product or variant has **one active price** at a time: that is what a shopper is charged (see `PriceResolver`); a variant without a price of its own is sold at the product's price. A switched-off price is a draft, and switching a new one on while another is on is refused, so a change is always two deliberate steps. A price needs a real amount (more than zero, at most two decimals, whole units for currencies without cents such as yen), and the optional *original price* shown struck through must be higher than the price. Old prices in another currency are shown as not in use; they can be deleted or left alone.

The shop only ever shows a price that can be charged (active, in the shop's currency; `Price::scopeChargeable()`, the same definition `PriceResolver` uses at checkout), cheapest first, so an obsolete or foreign-currency price is never displayed. The server also tells the storefront whether a product can be bought (`ProductAvailability::canBuy()`), and the add-to-cart button follows that rather than guessing from the prices it was sent.

### Options and variants

A variant is one combination of a product's options (Medium / Red). The options are kept on the variant by name (`{"Size": "M"}`), so:

- **Renaming** an option or a value rewrites the variants made from it; **deleting** one is refused while a variant is made from it.
- A variant must choose a value for every option that has values, and no two variants of a product may share a combination or a SKU.
- **Create the missing variants** makes one for every combination that does not have a variant yet (up to 100 a product, with generated SKUs such as `HOODIE-M-RED`). It can be run again after adding a value.
- Exactly one variant is the default; the first one added becomes it, and deleting the default promotes another. The default is changed by making another variant the default, never by clearing it, so a product that has variants always has one.
- **A product that has variants is only sold as one of them.** The cart refuses a missing variant, and checkout refuses a cart line for the base product once the product has gained variants. Otherwise switching every variant off would let the product be bought at its own price and skip the variants' stock.

### Deleting, archiving and switching off

Anything that has been **ordered** is kept for the order history and the stock ledger, so it cannot be deleted: archive a product (switch *On sale* off) or switch a variant off instead. Both disappear from the shop at once, and a cart that holds one fails at checkout with "no longer available" rather than charging for something withdrawn. Anything **never ordered** can be deleted, and its options, values, variants, prices (which are polymorphic and so have no foreign key), stock and category and tag links go with it. Carts lose the lines for it: a cart line whose variant was deleted would otherwise quietly turn into a line for the base product at the base price.

### Stock adjustments

Stock is tracked per product, or per variant when a product has variants (a product or variant with no stock row is always available). On a tracked item staff can **set** the count to a counted figure, or **add or remove** some (a delivery, damage), each with an optional note. `quantity` is what can still be sold, because orders take from it while they wait for payment and return it if cancelled. Every change is a movement in the ledger with reason `adjustment`, who made it and why, next to the movements orders make, and the product page shows the latest eight. The count cannot go below zero unless the item allows backorders. Changes lock the row while the new level is worked out, so a sale cannot slip in between the read and the write. Stopping tracking makes the item always available again and deletes its history, so it is **refused once orders have used the stock**: cancelling an order or refunding one returns exactly what its movements say it holds, and deleting them would lose that stock for good. To keep selling past zero, allow backorders instead.

Tracked stock at or below `COMMERCE_LOW_STOCK_THRESHOLD` (default 5) is labelled *Low* and listed under *Running low* on the Commerce overview. It only labels; the shop keeps selling until the count reaches zero. Only stock that belongs to something on sale counts (`InventoryItem::scopeForSale()`): an archived product or a switched-off variant keeps its row for the history, but is not a shortage, and could otherwise fill the list and hide real ones.

## Product pictures

A product's **Pictures** tab takes JPEG, PNG, WebP and GIF uploads (drag and drop, several at once; up to 5 MB each and 12 a product). The first picture is the product's main one; the others can be moved earlier or later, given a description for people who cannot see them (it falls back to the product name) and deleted.

**Nothing the shop serves is the file that was uploaded.** `ImageProcessor` decodes the upload as a picture and draws it again at three sizes (1600, 800 and 320 pixels on the longest side, never enlarged), encoding each as WebP. That means:

- Anything that is not a picture is refused whatever it is called: the file-type rule goes by what a file looks like, but the real check is that it decodes. An SVG (a document that can carry script) is not accepted at all.
- Metadata does not survive: location, camera, embedded profiles, and anything hidden in the file. A file that is a picture and something else at once (a "polyglot", with a script appended) comes out as only the picture.
- The size the file *declares* is checked before it is decoded, against `COMMERCE_IMAGE_MAX_PIXELS` (default 16 million, a 4000 × 4000 photo). Decoding needs memory in proportion to the pixels, so a few kilobytes that describe a gigantic picture are refused instead of exhausting the server.
- A photo taken sideways is turned upright first if the `exif` extension is available; transparency is kept.
- Several uploaded together are processed one by one: a refused file is listed with its reason and the others are kept.

Files live on `COMMERCE_IMAGE_DISK` (default `public`, which needs `php artisan storage:link`) under `products/{id}/`, and the rows and files are kept in step: files are removed again if the row cannot be saved, a deleted picture's files go with it, and a deleted product's files go once the deletion has committed. The positions are changed under a lock, and an order that names the wrong set of pictures is refused, so two people arranging at once cannot lose or invent one.

The pictures need PHP's `gd` extension with WebP support. Without it uploads are refused with a message saying so; the rest of the shop is unaffected.

## The storefront

The shop window sends only what a shopper should see:

- **Prices** that can be charged (active, in the shop's currency), cheapest first, and a `can_buy` flag decided with the same rules checkout uses (see [Prices](#prices)).
- **Variants** that are on sale, with the options they are made from, so the product page can offer Size and Colour as choices, strike out a value that does not come in the other things picked, and show the price and stock of the combination. A variant is sold at its own price, or the product's when it has none (`ProductAvailability::priceFor()`, the rule `PriceResolver` follows); a variant with neither is not for sale, so the page opens on one that is and turns add to cart off, with an explanation, if a shopper picks the other.
- **Stock in words, never numbers**: *In stock*, *Only a few left* (at or below `COMMERCE_LOW_STOCK_THRESHOLD`), *Out of stock*, or *Available to order* when backorders are allowed. A variant without a stock row of its own uses the product's, the same rule an order follows when it takes stock. Exact counts are never sent to the browser. A product is marked *Sold out* in the catalogue when everything that can be bought is out (a variant with nothing to charge does not count either way, and something that cannot be bought at all is unavailable, not sold out), and its add-to-cart button is off.
- **Pictures** at three sizes: the thumbnail in lists and the cart, the medium size in the catalogue and gallery, and the large one for big screens (`srcset`), with the product name as the description when none was written.
- **Structured data and sharing**: the main picture is the page's share image, and the page's JSON-LD includes every picture and, when it can be bought, an offer with the lowest price and its availability. That price is the lowest a shopper could actually pay, worked out from what each buyable variant is charged, so a product price that every variant overrides is never advertised.

Products with nothing to choose (no variants) can be added to the cart straight from the catalogue; the rest go through their page.

## Managing orders

**Commerce → Orders** in the ACP lists every order, newest first, with tabs for each status (and how many are in it), a search by order number, name or email, and a filter by payment state. Opening an order shows its items and totals, customer, addresses, payments (with a link to the payment in the provider's dashboard), refunds and its history.

| Action | What it does | Permission |
|--------|--------------|------------|
| Mark as fulfilled | Moves a paid order to `completed`. Optional carrier, tracking number and tracking link are kept on the order and shown to the customer; the customer is emailed unless you untick it. | `commerce.acp.edit` |
| Cancel order | Cancels an **unpaid** order and gives its stock back. A paid order is refunded instead. | `commerce.acp.edit` |
| Check payment | Asks the provider what became of an unpaid order's payment, for when its webhook was late or lost. | `commerce.acp.edit` |
| Add a note | A note for the team, kept in the history. Customers never see it. | `commerce.acp.edit` |
| Refund | Sends money back (below). | `commerce.acp.refund` |

Refunding has its own permission because it cannot be undone. The `admin` role has every permission; give `commerce.acp.refund` to other staff roles in Access control only if they should be able to.

The history (`order_events`) records payment, fulfilment, cancellation, refunds and notes, each with who did it. It is append-only.

The tracking link is shown to customers as a link, so only `http` and `https` addresses are accepted.

## Refunds

A refund is a record of its own (`refunds`), not just a number on the order, because it can be pending at the provider, fail, or be made outside the shop. `App\Support\Commerce\OrderRefunder` is the only place one is created or changes state.

**How a refund runs.**

1. Inside a transaction that locks the order, the refund is checked and saved as `pending`: the order must be paid, the amount must be in the order's currency and greater than zero, and it cannot exceed what is left (the total less refunds that succeeded **or are still pending**, so two staff members cannot refund the same money twice).
2. After that commits, the provider is asked to send the money back. The refund's idempotency key goes with the request, so repeating it cannot refund twice.
3. The provider's answer is applied: `succeeded`, `pending` (some payment methods take days), or `failed`.

**When the answer is unknown** (the connection dropped, Stripe errored), the refund stays `pending` and holds its balance. It is *not* marked failed, because it may exist at the provider. **Check status** on the refund asks Stripe for all its refunds on that payment (following every page of the list, not just the first hundred) and matches them to ours (each is created with our refund id in its metadata). If Stripe has no record of it ten minutes later, it never arrived and is marked failed so the money can be refunded again.

**What the order shows.** `refunded_total` and `payment_status` are recomputed from the succeeded refunds every time one changes; they are never edited, so repeated or reordered provider messages cannot make them drift. A refund can succeed and later fail (a closed card account, for example): the order goes back to `paid` or `partially_refunded`. If that refund had cancelled the order (a full refund before it shipped) the order is put back to `processing`, any stock the refund returned is taken again (even if that leaves it negative, because the goods are owed) and the history says so. Staff should then refund again or contact the customer, who was told the first refund went through. An order that had already shipped was never cancelled by its refund, so it stays `completed`.

**A full refund of an order that has not shipped cancels it** (`processing` becomes `cancelled`): there is nothing left to send. A shipped order stays `completed` with payment status `refunded`.

**Stock.** Ticking *Put the items back in stock* adds the order's stock back (movement reason `restock`) when the refund completes the order. It is only offered then, because a partial refund cannot say which items came back. Like `release`, only what the order still holds is returned, so it can never add stock twice.

**Recorded by hand.** If you returned the money another way (cash, a bank transfer), record it: the refund is saved as succeeded without asking the provider. An order whose provider cannot refund (it declares no `refunds` capability, or there is no payment reference) can only be recorded this way.

**Refunds made in the provider's dashboard** are picked up too. The refund webhooks only say *which payment* changed; the refunds themselves are then read from Stripe, so the shape of the event does not matter. A refund made in the dashboard is recorded without an email to the customer (whoever made it is looking after them), and a refund made on a duplicate payment (the customer paid twice) is logged and not applied to the order.

**Emails.** When a refund first succeeds the customer gets an email with the amount and a link to their order, unless staff untick *Email the customer* (a refund made in the dashboard never sends one). Marking an order fulfilled sends a shipping email the same way.

`StripeProvider` implements `refund()`, `syncRefunds()` and `paymentUrl()` from the contract; a provider that cannot refund simply leaves `Capability::REFUNDS` out of `capabilities()`.

## The live quote

As the shopper fills in the form, the page asks the server for the totals (`GET /checkout?ship_country=…&ship_region=…&rate=…`, a partial reload of the `quote` prop). Only the country, region and chosen method are sent, never prices. The same `OrderPricer` produces the quote and the order, in a lenient mode for the quote (it reports what is missing) and a strict mode for the order (it refuses anything incomplete or no longer offered), so the two cannot disagree. A signed-in customer's default saved address is priced from the first render. The quote also carries the discount code on the cart (`coupon`) and what it takes off, including the shipping discount, so the page can strike out shipping a free-shipping code waives.

## Stock

`inventory_items` holds the on-hand quantity. A product (or variant) with **no** inventory row is not tracked and always available; give it a row to track it. A variant's own row is used first, then the product's row. `allow_backorder` lets the level go negative.

Every change is written to `inventory_movements` (`reservation`, `release`, `restock`, and `adjustment` for counts by staff), so a reservation is released exactly once and a stock level can always be explained. Unpaid orders hold stock for `COMMERCE_PAYMENT_WINDOW` minutes (default 60, minimum 30 because Stripe sessions cannot expire sooner). The scheduler runs `ExpirePendingOrders` every five minutes (`php artisan commerce:expire-orders` does the same by hand). Before cancelling, it asks the provider what happened to the payment: an order that was paid just as its window ran out is settled, not cancelled, and an order is left alone if the provider cannot be reached. **The scheduler (`php artisan schedule:run` every minute) and a queue worker must be running in production.**

A payment that arrives for an order that was already cancelled is honoured: the order is reinstated, the stock is taken again (even if that leaves it negative) and the order is flagged `metadata.late_payment` and logged, so a person can check it.

## Payment providers

`App\Payments\Contracts\PaymentProvider` is the contract; the shop never talks to Stripe (or, later, Tebex) directly. Providers are registered in `config/commerce.php` and resolved by `PaymentManager`.

- The **store-level provider** is `COMMERCE_PROVIDER` (default `stripe`) and can be changed in the ACP under System settings. Every order records the provider it was placed with, and webhooks and reconciliation use that recorded provider, so switching never strands an in-flight payment.
- Providers declare `capabilities()` (one-time payments, subscriptions, refunds, hosted checkout, ...) and the shop checks those rather than the provider's name.
- To add a provider: implement the contract, register it under `commerce.providers`, and route its webhooks through `WebhookReceiver` (below).

### Stripe

Set `STRIPE_KEY`, `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` (the same credentials Cashier uses). Stripe is only offered at checkout when the secret **and** the webhook signing secret are set, because without the secret a payment could never be confirmed.

Point a Stripe webhook endpoint at `/stripe/webhook` and enable, in addition to the subscription events already listed in the README:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`
- `refund.created`, `refund.updated` and `refund.failed` (so refunds that are pending, fail later or are made in the Stripe dashboard update the order)
- `charge.refunded` (optional: a second signal that something was refunded)

Refund events for payments this shop did not take (subscription invoices, for example) are ignored.

With the Stripe CLI: `stripe listen --forward-to http://localhost:8000/stripe/webhook`.

How the Stripe integration protects the order:

- The Checkout Session is built from our order's lines in exact minor units. Shipping and each tax are sent as their own lines, and the shipping address is attached to the payment so it shows in your Stripe dashboard. If the order has any other amount that is not a product line (a discount, for now) the provider refuses to create the session rather than charge a different total.
- When Stripe reports a payment, the amount and currency are compared with the order before anything is marked paid. A mismatch is logged at `critical`, the payment is set to `review`, and the order is **not** fulfilled.
- The session must name the order we have on record (`client_reference_id`), and only sessions created for shop orders (`metadata.source = commerce`) are handled; plan-subscription checkouts are ignored.
- The return link is signed, and the session is created with an idempotency key, so repeating a request does not create a second session.

## Webhooks

`App\Payments\Webhooks\WebhookReceiver` is the pattern every provider's webhook follows. Each delivery is stored in `billing_webhook_calls` under `(provider, external_id)`. If that event was already processed the delivery is acknowledged and nothing runs again, so a provider's retries are harmless. The work runs in a transaction: an event is fully applied or not at all. If processing fails, the error and attempt count are stored on the call and a `500` is returned so the provider redelivers.

`/stripe/webhook` stays registered even if the shop section is switched off in the ACP, so payments in flight still settle. The customer's receipt link also keeps working.

## Rate limits

Starting a checkout uses the shared `billing` limiter (10 requests a minute). The confirmation page, which re-checks a pending payment each time it loads, has its own `checkout-status` limiter (30 a minute, per order), so waiting on a slow payment never competes with starting a checkout. The page checks about seven times over a little more than a minute, slowing down as it goes, and then stops.

## Money

Prices are `decimal(10,2)` columns, but all arithmetic is done on integers in `App\Support\Commerce\Money`, which also knows zero-decimal currencies (JPY, KRW, ...). Three-decimal currencies (KWD, BHD, ...) are not supported. The shop sells in one currency, `COMMERCE_CURRENCY` (falls back to `CASHIER_CURRENCY`); a price in any other currency cannot be added to a cart.

## Configuration

| Variable | Default | Purpose |
|----------|---------|---------|
| `COMMERCE_CURRENCY` | `CASHIER_CURRENCY`, else `USD` | Currency the shop sells in. |
| `COMMERCE_PROVIDER` | `stripe` | Store-level payment provider (the ACP can override). |
| `COMMERCE_GUEST_CHECKOUT` | `true` | Allow buying without an account. |
| `COMMERCE_PAYMENT_WINDOW` | `60` | Minutes stock is held for an unpaid order (minimum 30). |
| `COMMERCE_ORDER_PREFIX` | `MF` | Prefix of the order number. |
| `COMMERCE_LOW_STOCK_THRESHOLD` | `5` | Tracked stock at or below this is shown as low in the ACP and as "only a few left" in the shop. |
| `COMMERCE_IMAGE_DISK` | `public` | Disk product pictures are stored on. It must be one the web can serve. |
| `COMMERCE_IMAGE_MAX_PIXELS` | `16000000` | Largest picture accepted, in pixels (width × height). |

## Upgrading an existing installation

Run `php artisan migrate`. The new migrations are safe on a database that already has data. For discount codes: `coupons` and its two limit tables are new, carts gain a `coupon_id` and orders gain `coupon_id` and `coupon_code` (empty for existing orders, which had no discount). Staff who can create, edit and delete in Commerce can manage codes; no new permission is needed. The order confirmation email gains a discount line when there is one. For product pictures: `product_images` is a new table; make sure `php artisan storage:link` has been run and that PHP has the `gd` extension (with WebP). The product page in the shop was rebuilt (gallery, options, price, stock wording, add to cart) and no longer sends stock counts to the browser. For catalogue management: variants gain an `is_active` flag (existing variants stay on sale), and the commerce overview no longer carries the old quick-create forms: use Commerce → Products. For order management and refunds: `refunds` and `order_events` are new tables and orders gain a `refunded_total` (zero for existing orders). Run `php artisan db:seed --class=RolePermissionSeeder` to create the `commerce.acp.refund` permission (the admin role is given it; grant it to other staff roles in Access control), and add the five refund events above to your Stripe webhook endpoint. For the shipping, tax and address slice: products gain *Needs shipping* and *Charge tax* flags (existing products stay shipped and taxed), orders gain empty address and shipping-method columns, and the address, shipping and tax tables are new. **Behaviour change:** products are shipped by default, so checkout now asks for a shipping address; with no shipping zones configured it still ships anywhere for free, as before. For the first slice:

- `add_checkout_columns_to_orders_table` adds the new order columns and **backfills** existing orders: each gets a public id and number, an owner (the placing user), and a payment state implied by its old status (`processing` and `completed` become `paid`).
- `generalise_billing_webhook_calls_table` adds `provider` and `external_id` (copied from `stripe_id`) so shop and subscription events share one idempotency key.
- `create_payments_table` and `create_inventory_movements_table` are new.

The header cart previously showed an estimated 7% tax and a flat shipping charge that were not real; it now shows the subtotal only.

## Not built yet

Planned for the rest of M1 (see the roadmap): gift cards, orders that cost nothing, digital goods (downloads and licence keys; the *Needs shipping* flag is already in place), reviews and wishlists. A guest's cart is not yet carried over when they sign in, and a guest's orders are not yet attached to an account created later with the same email. Tebex arrives in M2 behind the same provider contract.
