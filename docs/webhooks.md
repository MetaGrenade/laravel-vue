# Webhooks

Stripe and Tebex tell the shop what happened to a payment. A delivery is kept once, under the sender and the id the sender gave it. One that was already kept is acknowledged and not applied again. A signature that does not match is refused and kept nowhere.

> **Status:** built for Stripe and Tebex.

## Stripe

One address, `POST /stripe/webhook`, for shop orders and for subscriptions. The signature is `Stripe-Signature`, checked against `STRIPE_WEBHOOK_SECRET` (and `STRIPE_CLI_WEBHOOK_SECRET` when that is set). The time in the signature may be at most `STRIPE_WEBHOOK_TOLERANCE` seconds away from now (300 by default). Older than that, or further ahead than that, is refused. `0` accepts any time.

A shop payment is applied through the shared receiver: the event id is stored, and a second delivery of that id changes nothing. A subscription event (an invoice paid or failed, a subscription deleted, a trial about to end) is applied once it has been kept. A later delivery of the same event is answered and left alone. Running it from **Billing → Webhooks** still applies it, which is how a failed one is tried again. The payment emails are also claimed once, so a replay does not write to the customer twice. See [billing.md](billing.md#webhook-events).

Keeping the archive on is what makes the second subscription delivery a no-op (`BILLING_WEBHOOK_STORE`, on by default). With it off, a repeat runs the handler again; the emails are still sent once.

## Tebex

`POST /webhooks/tebex`. Tebex sends only from two addresses, and signs the body. The signature has no time in it. A repeat is stopped by the delivery's id, and a copy sent from anywhere else is told the page does not exist. See [tebex.md](tebex.md#who-may-send).

## Testing

`tests/Feature/Webhooks/StripeWebhookTest.php`: a subscription event delivered again does not change the subscription, and a signature an hour old or an hour ahead is refused. `tests/Feature/Commerce/StripeCommerceWebhookTest.php`: a shop event delivered again does not pay the order twice, and a stale signature is refused. `tests/Feature/Tebex/TebexWebhookTest.php`: a delivery from another address is not found, a bad signature is refused, and the same delivery again changes nothing.
