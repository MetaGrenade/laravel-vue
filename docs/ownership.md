# Ownership: who a record belongs to

In 1.0 a person owns their own orders, saved addresses and entitlements. In 1.1 a **team** can own them too. So that 1.1 does not mean rewriting every place that asks "may this person see this?", 1.0 already asks it the way 1.1 will: **through the record's owner, never through a user id.**

> **Status:** built: the owner on every record that is billable or that a team could share, the policies and queries that follow it, and the tests that prove it. **Not built:** teams themselves (1.1), see [Upgrading to teams](#upgrading-to-teams-planned-for-11).

## What carries an owner

A record has an **owner** (`owner_type` and `owner_id`, a morph) and, separately, may have a `user_id` that says **who acted** (who placed the order, who clicked). They are different questions, and only the owner decides who may see it.

| Model | Owner | `user_id`, if any |
|---|---|---|
| `Order` | The customer (a guest's order has none and is reached only by its signed link). | Who placed it. |
| `Payment` | The order's owner. | none |
| `Address` (the address book) | Whose book it is in. | none |
| `ProviderSubscription` (a recurring payment) | Whose it is. | none |
| `UserEntitlement` (what a purchase or staff gave) | Who holds it. | none |

These use the `HasOwner` trait. A plan's entitlements are read from the owner's live Stripe subscription rather than copied ([entitlements.md](entitlements.md#subscription-plans)).

**Personal on purpose:** things that are about a person and not about what they pay for stay on the person: their carts, wishlist, reviews, tickets, forum posts, notification settings and API keys. A ticket is opened through `SupportTicketPolicy`, which asks who opened it. A team may share some of these one day; that is a decision for 1.1, not something 1.0 should guess. See [record-policies.md](record-policies.md).

## How access is decided

`App\Support\Ownership` says which owners a user acts for (`Ownership::ownersFor($user)`) and which owner a record they create belongs to (`Ownership::newRecordOwnerFor($user)`). In 1.0 the answer is the user themselves, both times. Everything else is built on those two:

| Question | Ask | Never |
|---|---|---|
| May this person see/change this record? | The policy (`OrderPolicy`, `AddressPolicy`), which calls `$record->isOwnedByAnyOwnerOf($user)`. | `$record->user_id === $user->id` |
| Which records does this person see? | `Model::query()->visibleTo($user)` | `->where('user_id', $user->id)` |
| Which records does this owner have? | `Model::query()->ownedBy($owner)` | |
| Who owns what this person creates? | `$record->assignOwner(Ownership::newRecordOwnerFor($user))` | `owner_id = $user->id` |
| What does this person hold? | `$user->canUse('x')`, `$user->limitOf('x')`, `app(Entitlements::class)->forUser($user)` (all owners they act for, added up) | Reading `UserEntitlement` by `user_id` |

An owner of another kind is never mistaken for a user with the same key: `isOwnedBy()` checks the type as well as the id.

## How it is tested

`tests/Feature/Ownership/OwnerResolutionTest.php` gives a user a **second owner**, a stand-in for a team (`Tests\Support\StandInTeam`), through `Ownership::resolveUsing()`, and checks that:

- orders, addresses, recurring payments, payments and entitlements of that owner are visible to the user, on the pages as well as in the policies and queries, and those of another team or user are not;
- the `user_id` of the person who acted grants nothing (an order the user placed for a team they do not act for is not theirs);
- a team whose id is the user's id is not the user;
- limits add up across the owners a user acts for;
- new records go to the owner the user's new records belong to, and `AddressBook::remember()` looks there for a copy;
- the same user without the team sees none of it (so the owners given, not the data, grant access);
- **no policy of an owned model mentions `user_id`, and no code under `app/` finds an owned model by it.** These two read the source, so they are blunt: a failure is either code going round the owner, or code with a good reason that belongs in an allowance in the test.

`resolveUsing()` is the extension point 1.1 uses. Nothing in 1.0 calls it outside the tests, and `Tests\TestCase` clears it after every test.

## Rules for new code

1. A new table that is billable, or that a team could share, carries `owner_type` and `owner_id` and the `HasOwner` trait. A new table about a person does not need them.
2. Ask the policy or `visibleTo()`; do not write `user_id` into a check or a query on an owned model.
3. Write `user_id` when it records who acted; read it to show who acted. Do not use it to decide who may see.
4. Add the model to `OwnerResolutionTest` (a record owned by the user, by a team they act for, and by one they do not).

## Upgrading to teams (planned for 1.1)

What will change, so adopters know what to expect. None of it is built:

- **A `Team` model and memberships.** `Ownership::resolveUsing()` is given the user's own account and the teams they belong to, and the team new records go to (the one they are working in). The policies, scopes, address book, order history and `Entitlements::forUser()` follow without change; that is what the tests above prove.
- **Billing.** Provider-neutral records (`orders`, `payments`, `provider_subscriptions`, `user_entitlements`) already point at an owner. Cashier's own tables keep `user_id`; a team is billed through `Cashier::useCustomerModel()` or a billable-owner step in front of it. All Cashier calls already go through `SubscriptionManager`, so the change is made there. `PlanEntitlements::gives()` reads subscriptions for a user only today (it returns nothing for any other owner) and is widened then.
- **Roles.** Staff keep global Spatie roles. Team roles (who in a team may place orders or manage billing) are a 1.1 decision; 1.0 leaves a place for them in the policies and adds none.
- **Personal things** (above) stay on the person unless 1.1 decides a team shares them.
- **Data you hold.** Records already carry `owner_type = App\Models\User` and the user's id, so nothing needs migrating to start; moving a record to a team later changes its owner.
