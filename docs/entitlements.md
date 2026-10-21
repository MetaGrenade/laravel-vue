# Entitlements

An **entitlement** is something a purchase gives an account in the shop: a feature that is switched on, or a limit that is raised. It is the layer between "paid" and "allowed": a Tebex package is paid for, Tebex delivers its own goods in the game, and the shop gives the buyer's *account* whatever the package is set to give, so that code can ask "may this user do that?" without knowing who took the money.

> **Status:** built for **Tebex purchases** and **Stripe subscription plans** (the `feature` and `limit` kinds). The shop's own products are not wired to it yet (they will use the same layer: see [What is not built](#what-is-not-built)).

## What an entitlement is

| | |
|---|---|
| **Key** | What code asks for: lower case, starting with a letter, words joined by dots, dashes or underscores (`reports`, `team.seats`, `api-rate_limit`). Up to 64 characters. It never changes once the entitlement exists. |
| **Kind** | **Feature:** on or off. **Limit:** a number; the grants of it **add up** (a package that adds 5 seats, bought with another that adds 3, is 8). The kind never changes either, because it decides what a grant's value means. |
| **Name, description** | For people. These can be changed. |

Only these two kinds exist so far, on purpose. A **role**, a **badge** or a **download** would have to change something outside this layer, and a package that could hand out a role is a way to escalate privileges, so each is added when something is wired to honour it, with its own allow-list.

## Choosing what a package gives

Entitlements are made in **Commerce → Entitlements** in the ACP. Which Tebex package gives which is set on **Commerce → Tebex**: the **Gives** column of the package list has a pencil that opens a list of the entitlements to tick. For a limit you say how much **one unit** of the package adds (a buyer who takes two gets twice that).

Tebex owns the catalogue and knows nothing of this, so a synchronisation never touches it. It decides what a purchase made **from now on** gives; **orders already paid keep what they were given** (the amount is fixed when the order is paid).

## Subscription plans

A **subscription plan** (Stripe, billed through Cashier) gives its entitlements to every account subscribed to it. The difference from a purchase is that **nothing is written when someone subscribes**: an account holds what the plans of its **live subscriptions** give, worked out each time it is asked. There is no copy to fall out of step, so a plan changed by staff, a subscription swapped to another plan, cancelled or ended reaches the account the next time anything asks. A plan **and** a purchase add up: a limit of 10 from the plan and 5 from something bought is 15, and a feature is on if either gives it.

**Choosing what a plan gives.** In the ACP, **Billing → Plans**: the **Gives** column has a key icon that opens the same list of entitlements to tick (it needs `billing.acp.edit`). For a limit you say how much **the plan** gives, once; it is not multiplied by anything. Unlike a package, this reaches **everyone on the plan at once**, the people already subscribed too. The example plans (Starter, Pro) are seeded with two example entitlements, `projects` (a limit) and `priority-support` (a feature), from `config/billing.php`; the seeder adds and updates them and never takes away what staff changed.

**When a subscription counts.** As Cashier says it does:

| The subscription | Gives what its plan gives? |
|---|---|
| Paid and running (`active`) | Yes. |
| On a trial | Yes. |
| Cancelled but still inside the period that was paid for | Yes, until that period ends. |
| Ended (cancelled and past its end date) | No. |
| **Past due**, incomplete, incomplete and expired, unpaid | No. |

Past due is the one to decide on: by Cashier's default access stops at the first failed payment. To keep it while Stripe retries, call `Cashier::keepPastDueSubscriptionsActive()` in a service provider (dunning emails and retry handling are the next slice of the SaaS milestone).

A subscription is matched to its plan by **Stripe price**, as the billing page and the dashboard do, so a subscription to a price no plan carries gives nothing, and neither does one with several prices. A plan switched off on the pricing page (**Active** off) still serves the people already on it. Two live subscriptions each give what their plan gives.

**Not taking something away by accident.** Because the plan is what gives, three things are refused while people are subscribed to it: **deleting the plan** (switch it off instead), **pointing it at a different Stripe price** (make a new plan), and **deleting an entitlement a plan gives** (take it off the plan first).

## When it is given

When an order is paid, each line whose package has entitlements gives them, once per line and entitlement (a payment reported again changes nothing). They belong to **whoever owns the order**:

- A signed-in buyer's order gives its grants to their account.
- A **guest's order has no owner**, so its grants belong to nobody and are of no use to anyone. When the guest later verifies an account with the same email, the order is claimed and its grants go to the account with it.
- A payment held for review (the wrong packages, the wrong currency) is not paid, so it gives nothing.
- A **renewal** of a recurring package gives nothing more: the first payment gave it, and it lasts as long as the recurring payment does.

## When it is taken back

Entitlements follow the money and the recurring payment, in the way downloads do. After every change that matters, each grant of the order is worked out again from what is true now, so it is safe to repeat and to run in any order:

| What happened | The grants |
|---|---|
| The order is **refunded in full** (at Tebex, or by hand in the shop), or a **dispute is lost** (the money is gone, which the shop records as a refund) | Taken back (`refund`). |
| The refund **fails** afterwards | Given back. |
| The **recurring payment ends** (Tebex sends `ended`, or its status becomes Expired or Cancelled) | Taken back for **the recurring package only** (`subscription`). A one-off package in the same order keeps what it gave. |
| The recurring payment is **live again**: a later delivery (not the `ended` one, and not older than it) says it is Active or Overdue, after an `ended` or an Expired or Cancelled status | Given back. |
| Cancelling is **asked for** (it runs until the end of what was paid for), a renewal **fails** (Tebex tries again), or a dispute is only **opened** | Nothing. |
| Both a refund and the end of the recurring payment | Taken back; the refund is the reason that is kept, and it stays taken back even if the recurring payment is live again. |

A grant taken back by a **person** (a reason other than the two above) is never touched by this: it stays taken back. The recurring payment can be reported ended **before** its payment arrives (Tebex does not promise the order); the grants are then given and taken back at once.

## Asking in code

Everything asks the database and remembers nothing, so an answer is never older than the last change: a query for the grants, one for the account's subscriptions, and one more for the plans of those that are live. When several things depend on it, ask once and keep the set.

```php
$held = $user->entitlementSet();   // everything the user holds, from every owner they act for
$held->has('reports');             // a feature that is on, or a limit above nothing
$held->limit('team.seats');        // 0 when they hold none

$user->canUse('reports');          // the same, for one key
$user->limitOf('team.seats');

app(\App\Support\Entitlements\Entitlements::class)->for($owner);   // one owner (a user today, a team in 1.1)
```

**Routes** are gated with the `entitled` middleware, which needs **every** key named. A guest is sent to sign in; a user without it gets a 403. Naming no key is an error, so it can never let everyone in by mistake.

```php
Route::get('/reports', ReportController::class)->middleware(['auth', 'entitled:reports']);
Route::post('/team/invite', InviteController::class)->middleware('entitled:team.seats,invites');
```

A limit says whether the user may do something, not how much they have used: compare `limitOf()` with your own count.

**The page** is given what the signed-in user holds as `auth.entitlements` (`{ features: string[], limits: Record<string, number> }`), and `useEntitlements()` reads it:

```vue
<script setup lang="ts">
import { useEntitlements } from '@/composables/useEntitlements';

const { can, limit } = useEntitlements();
</script>

<template>
    <ReportsLink v-if="can('reports')" />
    <p>You have {{ limit('team.seats') }} seats.</p>
</template>
```

That is for showing and hiding things only. The server decides what a user may do: always gate the route or the action, not just the button.

## Where it shows

- **Commerce → Entitlements:** the list, with how many packages and plans give each and how many purchases hold it. One cannot be **deleted while a purchase still holds it** or **a plan with people on it gives it**; take it off the packages and plans, and it stops being given from now on.
- **Commerce → Tebex:** what each package gives.
- **Billing → Plans:** what each plan gives, and the key icon to change it.
- **The pricing page and Billing settings:** each plan lists what it gives (a limit with its amount), then the highlights staff wrote about it.
- **An order (ACP):** an *Entitlements* card: what it gave, whether each is still held, and why one was taken back.
- **The customer's orders page:** *What your plan and purchases give you*: each once, with limits added up, and nothing that was taken back.

## What is stored

| Table | |
|---|---|
| `entitlements` | The list: key, name, description, kind. |
| `tebex_package_entitlements` | What each Tebex package gives, and how much per unit for a limit. |
| `subscription_plan_entitlements` | What each subscription plan gives, and how much for a limit. Read, never copied: there is no grant per subscriber. The shop's own products will get a table of the same shape. |
| `user_entitlements` | The grants. An owner (`owner_type`, `owner_id`; empty for a guest's order), the entitlement, the source (`tebex`), the order and order line that gave it (kept as empty if the order goes), the value (for a limit: the package's amount times the quantity), `expires_at`, and `revoked_at` with the reason. One grant per order line and entitlement. |

All dates are `DATETIME`, not `TIMESTAMP`, so none stops at 2038 on MySQL. Needs `php artisan migrate`; no new permission (the ACP pages use the existing commerce ones).

## What is not built

- **The shop's own products as a source.** The layer is provider-neutral, but only Tebex packages and subscription plans can be set to give something yet.
- **A baseline for people on no plan.** An account that is subscribed to nothing holds nothing from a plan, so code that wants a free tier either checks `limitOf()` against its own default or waits for the free tier (not decided yet).
- **Teams.** Only a user's own subscriptions are read; a team that is billed will be read the same way in 1.1.
- **The role, badge and download kinds** (above).
- **Giving one by hand.** The model has a `source` for it (`admin`), but there is no screen.
- **A package that lasts for a time.** `expires_at` is honoured when set, but nothing sets it yet.
- **Holding entitlements back while a dispute is open.** Only a lost dispute takes them back.

## Troubleshooting

| What you see | What it means |
|---|---|
| A buyer paid and holds nothing | The package gives nothing (look at its *Gives* on the Tebex page); or it was set after they paid (it applies to purchases from then on); or they bought as a guest and have not claimed the order (a verified account with the same email claims it); or the payment is held for review. The order's *Entitlements* card in the ACP says what was given. |
| A buyer holds something after a refund | The refund is still pending, or only part of the order was refunded. A grant is taken back when the order is refunded **in full**. |
| A recurring payment ended and they still hold it | Tebex has not said it ended (cancelling only stops the next renewal). See the recurring payment on the order. |
| A subscriber holds nothing from their plan | The plan gives nothing (look at **Gives** on Billing → Plans); or the subscription is not live (past due, incomplete, unpaid or ended: the account's Billing settings and the Stripe dashboard say which); or it is to a Stripe price no plan carries (the plan's **Stripe price ID** must be the price they pay). |
| "People are subscribed at this price now" | A plan's Stripe price cannot be changed while people are on it. Make a new plan for the new price, switch the old one off, and move the subscribers in Stripe. |
| "Entitlement ... is held through N purchases and cannot be deleted" | Take it off the packages that give it. The purchases that hold it keep it until they are refunded or the recurring payment ends. |

## Testing

`tests/Feature/Entitlements` covers the reading (the sets, adding limits up, what counts as held, the middleware, the page's props), what plans give (`PlanEntitlementsTest`: which subscriptions count, a plan swapped or changed reaching the account at once, plan and purchase adding up), the pricing and billing pages (`PlanPagesTest`) and the seeded examples (`PlanSeederTest`); `tests/Feature/Admin/SubscriptionPlanEntitlementsTest.php` covers choosing what a plan gives and the guards for the people on it; `tests/Feature/Tebex/TebexEntitlementsTest.php` covers the flow from payment to refund, dispute and the end of a recurring payment; `tests/Feature/Admin/EntitlementManagementTest.php` covers the ACP; and `tests/Feature/Database/IdentifierLengthTest.php` fails the build on any index name MySQL would refuse.
