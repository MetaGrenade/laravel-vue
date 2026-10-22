# Billing: subscriptions

How subscriptions to a plan are billed, and what happens when a payment does not go through. Subscriptions use **Laravel Cashier** and Stripe; the shop's orders (products, gift cards) are a separate flow described in [commerce.md](commerce.md), and what a plan gives an account is in [entitlements.md](entitlements.md#subscription-plans).

> **Status:** plans, subscribing, cancelling and resuming, invoices and the webhook archive have been there since the start. Added for 1.0: plans that give entitlements, what a customer is told and can do when a payment fails, and free trials. **Still to come in the SaaS milestone:** promotion codes on subscriptions, a metered-usage example and a link to Stripe's customer portal.

## When a payment fails

Stripe takes the payment for each renewal. When it cannot, it tries again on a schedule you set in Stripe (**Settings → Billing → Revenue recovery**: how many retries, how far apart, and what happens when they run out: cancel the subscription or leave it unpaid). The shop does not decide any of that; it follows what Stripe's webhooks say, and tells the customer.

**What the customer loses.** By Cashier's default, a subscription that is **past due** (or unpaid, or incomplete) does not count as live, so **what its plan gives is paused from the first failed payment** and comes back when the payment goes through. This is a decision, not an accident: the shop does not give away what it is not being paid for while Stripe keeps trying. To keep access while Stripe retries instead, call `Cashier::keepPastDueSubscriptionsActive()` in a service provider; the emails and the billing page then say the plan stays active. Even then it is paused once Stripe gives up (the subscription becomes unpaid or is cancelled), and they say so: the page says "paused" whenever the subscription is not live, which is exactly what decides whether its plan gives anything.

**What the customer is sent.** Three emails, each sent once:

| When | The email |
|---|---|
| A renewal payment fails (`invoice.payment_failed`) | *We couldn't take your payment*: the amount Stripe tried to take (`amount_due`, which is less than the invoice total when a credit or balance came off first) and the plan, that their plan is paused, when Stripe will try again (or that it will not), and a button to the billing page. Sent for **each** failed attempt, with *We still couldn't take your payment* from the second. |
| The payment goes through after having failed (`invoice.payment_succeeded`) | *Payment received, thank you*: the amount paid (`amount_paid`) and that the plan is active again. Not sent for an ordinary payment that never failed. |
| Stripe ends the subscription for want of payment (`customer.subscription.deleted` with the reason `payment_failed`) | *Your subscription has ended*, with a link to choose a plan again. A subscription the customer cancelled is not blamed on payment. |

Only a **subscription's** invoice is chased: one that names its subscription (`parent.subscription_details.subscription`, or a top-level `subscription` on a webhook endpoint set to an older Stripe API version). A manual or one-off invoice failing for the same customer is not. The very first payment of a new subscription is not chased either: the customer is on the page that asked for it, which says what went wrong.

Stripe does not promise to deliver events in order. A failure that arrives **after** the invoice was paid is stale: it does not mark the paid invoice failed and it does not email the customer, who has paid (it is still kept in the webhook archive).

Each notice is **claimed before it is sent**, in `billing_dunning_notices` (one row per kind, invoice or subscription, and attempt). Stripe delivers a webhook again when it is not answered in time, and an admin can replay one from **Billing → Webhooks**; neither tells the customer twice. If the email cannot be queued the claim is undone, so Stripe's next delivery of the same event tries again.

**What the customer can do.** The billing settings show a red notice while a payment is behind, with **Update payment method** and **Pay now**. Stripe does *not* try again when a card is changed, only on its schedule, so without **Pay now** a customer who had put their card right would wait, with their plan paused, for the next attempt. It asks Stripe to pay the subscription's latest invoice with the card on file and, if that works, brings the plan back at once rather than waiting for the webhook. It says the plan is back only when the subscription, as Stripe then reports it, is no longer behind and is live. If Stripe took the request but the money has not arrived (a bank debit takes days) they are told the payment is being processed, and the plan comes back when Stripe confirms it. If the card is declined the page says why (Stripe's own message). If the bank wants the customer (3-D Secure) they are sent to Stripe's page for the invoice. If Stripe cannot be reached they are asked to try again, and the error is reported. It is limited to six presses a minute.

## Free trials

A plan can give a new customer **free days before the first payment**: **Billing → Plans**, the *Free trial (days)* field (empty is no trial; up to 730, which is Stripe's limit; none by default). It applies to people who subscribe from then on.

- **The card is taken up front.** The customer adds a card as for any subscription. Stripe charges nothing until the trial ends and then takes the first payment; if that payment fails it is followed up like any other (see above).
- **One trial per customer.** Someone who has ever had a subscription of any kind (running, cancelled or ended) is charged from the start, so cancelling and coming back is not a way to another trial. A visitor with no account yet is eligible. (Someone who deletes their account and signs up again is a new customer, which cannot be told apart.) The check is made while the subscription is being started, under the per-user lock that deleting the account also takes, so two sign-ups at once cannot both get a trial.
- **The plan works from the first day.** A trial counts as live, so what the plan gives (its entitlements) is given for the whole of it.
- **The pages say so.** The pricing page and Billing settings show *N-day free trial* on the plan, *Start free trial* instead of *Start subscription*, and that the card is not charged until it ends, but only to someone who is eligible. Billing settings show *Free trial until* a date, and *Cancel trial*.
- **Cancelling during the trial** ends it on the day it would have ended, and nothing is ever charged.
- **A reminder before it ends.** Stripe sends `customer.subscription.trial_will_end` three days before the trial ends; the subscriber is emailed *Your free trial ends soon*: the date, what the plan costs after it, and that they can cancel before then. Sent once (the same claim table as the payment emails), and not for a trial that has been cancelled or has already ended. A trial shorter than three days is warned about at once, by Stripe.

## Webhook events

One endpoint, `POST /stripe/webhook`, signed with `STRIPE_WEBHOOK_SECRET`, for subscriptions and for shop orders. The subscription side needs these events switched on in Stripe: `customer.subscription.created`, `customer.subscription.updated` and `customer.subscription.deleted` (Cashier keeps the local subscription in step with them), `customer.subscription.trial_will_end` (the trial reminder), and `invoice.payment_succeeded` and `invoice.payment_failed` (invoices, and the emails above).

## What is stored

| Table | |
|---|---|
| `subscription_plans` | The plans, with their Stripe price, what they give and the days of free trial they offer (`trial_days`). |
| `subscriptions`, `subscription_items` | Cashier's copy of each subscription. A subscription has no foreign key to its user, so one can outlive its user (deleting an account ends its Stripe subscriptions first, but the row can stay; the webhook archive copes: an event about one is kept without the user). |
| `billing_invoices` | One row per Stripe invoice, written from the invoice webhooks. |
| `billing_webhook_calls` | The archive of every delivery, replayable from the ACP. |
| `billing_dunning_notices` | Which payment and trial emails were sent, so that each is sent once (`kind`: `failed`, `recovered`, `cancelled`, `trial_ending`). |

Needs `php artisan migrate`; no new permission.

## Troubleshooting

| What you see | What it means |
|---|---|
| A customer says they got no email about a failed payment | Check **Billing → Webhooks** for the `invoice.payment_failed` delivery: if it is missing, the event is not switched on in Stripe or the endpoint is not reaching the shop. If it is there, the mail queue (`mail`) may not be running. Replaying it does not resend an email that was already sent. |
| A customer was not offered the trial | They have had a subscription before (a trial is for first-time subscribers), or the plan has no trial days. The pages show the trial only to someone eligible. |
| A customer got no trial reminder | `customer.subscription.trial_will_end` is not switched on in Stripe, or the trial was cancelled or had already ended, or it was shorter than three days (Stripe then warns at once, when it starts). Check **Billing → Webhooks** for the delivery; replaying it does not resend a reminder that was sent. |
| A customer got no email for the first payment of a new subscription | By design: they are on the checkout page, which says so. |
| A customer's plan is paused though their card is fine | Their subscription is still past due: the card was updated but nothing asked Stripe to pay, so they should press **Pay now** (or wait for Stripe's next attempt). |
| **Pay now** says the payment is being processed | Stripe accepted the request but the payment is not confirmed yet (a bank debit, or Stripe has not updated the subscription). The plan comes back when the webhook says so; nothing more to do. |
| **Pay now** says Stripe could not be reached | A network or key problem on the shop's side, reported in the logs. Nothing was charged. |
| An email says "will try again on" a date that has passed | Stripe tried again and failed again (a new email follows) or has not had a chance to; the date is the one Stripe gave when the event was sent. |

## Testing

`tests/Feature/Billing/DunningTest.php` covers the three emails (the wording, once each, replays, the attempts, the first payment, unknown customers, a failure to send). `tests/Feature/Billing/PayNowTest.php` covers **Pay now** through the real Stripe SDK with the network replaced by scripted answers (`tests/Support/ScriptedStripeHttpClient.php`): paid, declined, 3-D Secure, an invoice Stripe already took, nothing to pay, Stripe unreachable, and the button and the page. `tests/Feature/Database/BillingDunningNoticesMigrationTest.php` covers the table. `tests/Feature/Billing/TrialsTest.php` covers who gets a trial and starting one through the real Stripe SDK with scripted answers, `TrialReminderTest.php` the reminder, `tests/Feature/Admin/SubscriptionPlanTrialTest.php` choosing the days in the ACP, and `tests/Feature/Database/PlanTrialDaysMigrationTest.php` the column.
