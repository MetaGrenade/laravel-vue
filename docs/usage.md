# Metered usage

A **meter** counts something a customer does, a month at a time, and holds them to what their plan allows. The one that comes with the shop counts **API calls**: every request made with an API token is a use, an account on the Starter plan may make 1,000 a month, one on Pro 10,000, and a request past that is refused. The same pieces count anything else you choose.

> **Status:** built: the ledger, a monthly allowance that comes from the plan, a middleware that counts and enforces (on every API route that needs a token), a usage panel for the customer, a report for staff, the data export and the pruning. **Not built:** billing the overage through Stripe (see [below](#billing-the-overage-in-stripe-not-built)) and periods that follow each subscription's own billing cycle (see [the month](#the-month)).

## How it works

- **A meter** is an entry under `usage.meters` in `config/billing.php`: a label, a unit, the **entitlement** that is its allowance, a **free allowance**, and what happens at the limit.
- **A use** is a row in the `usage_events` table: the account, the meter, how many (usually one), when, and, if the caller gave one, a key that makes the same use count once however often it is reported. API calls also keep which token made them.
- **The allowance** is the amount of the meter's **limit entitlement** that the account's plans give, added up as every limit is (see [entitlements.md](entitlements.md#subscription-plans)). An account whose plans give none has the meter's `free_allowance`; if that is empty (the default) it has **no limit** and is only counted. So a plan change visibly changes what a customer can do: it is the amount the plan gives, read each time.
- **At the limit,** the meter's `overage` decides: `block` (the default) refuses the request, `allow` carries on counting, for when you bill the extra some other way.

| Setting | What it is | Environment |
|---|---|---|
| `entitlement` | The limit entitlement whose amount, given by plans, is the allowance each month. | |
| `free_allowance` | What an account whose plans give none may use each month; empty for no limit. | `BILLING_API_FREE_ALLOWANCE` (API calls) |
| `overage` | `block` or `allow`. | `BILLING_API_OVERAGE` (API calls) |
| `usage.retention_months` | How long events are kept (see [retention](#privacy-and-retention)). | `BILLING_USAGE_RETENTION_MONTHS` |

## The example: API calls

The middleware `usage:api-calls` is on every authenticated API route group in `routes/api.php`. Laravel runs it **after authentication and the rate limits and before it looks up what the address names** (it is in the middleware priority list in `bootstrap/app.php`, so it does not matter where a group lists it). It:

- **counts** a request unless it failed on the server's side (a 5xx, or an exception) or was told to slow down (a 429: a route's own rate limit stops the request before the meter, a token's own quota after it): neither was the caller's use. A request the caller got wrong **is** a use: a 404 for something that is not there (the meter runs before it is looked up), a 422, a 403.
- **refuses** a request when the account has used all it may and the meter blocks: a **429** with `{"message", "meter", "limit", "used", "resets_at"}`, a `Retry-After` in seconds, and the headers below. A refusal is not counted, and the request never reaches what it asked for: an account at its limit gets the 429 even for something that does not exist, not a 404. (It is also not in the token activity log, which comes after the meter, as a token's own quota refusal is not.)
- **tells** an account that has an allowance where it stands on every response: `X-Usage-Limit`, `X-Usage-Remaining` and `X-Usage-Resets-At` (ISO 8601). An account with no limit gets no such headers.
- **does not count** a request with nobody signed in. Public read routes are not metered.

It is a **soft limit**: two requests made at the same moment can both pass when one use is left. If a use cannot be written to the ledger the request still goes through (the exception is reported, and that use is lost), because a broken ledger should not break the API.

A test fails if a route that needs a token is added without the meter, so new API routes cannot be left out by accident.

**The example plans.** `config/billing.php` gives Starter an `api-calls` allowance of 1,000 and Pro 10,000, and seeds the `api-calls` entitlement with them. On a site that already has plans, run the plan seeder again (it gives each plan what the config says, so it also sets the amounts of the other configured entitlements back to the config's, though it does not take away what staff added), or make the `api-calls` limit in **Commerce → Entitlements** and set the amount on each plan in **Billing → Plans → Gives**. Accounts with no plan are counted but not limited unless you set `BILLING_API_FREE_ALLOWANCE` (for instance `100`).

## Counting something else

Add a meter to the config (and, for an allowance from plans, a limit entitlement with the same key as its `entitlement`), then count a use wherever it happens:

```php
use App\Support\Usage\Usage;

// One use. The key makes it count once, however often it is reported (a retried job, a repeated webhook).
app(Usage::class)->record($user, 'exports', key: "export-{$export->id}");

// Several at once, or one that happened earlier.
app(Usage::class)->record($user, 'exports', quantity: 5, at: $when);
```

To hold an account to its allowance before doing the work, ask where it stands:

```php
$status = app(Usage::class)->status($user, 'exports');

if ($status->blocks()) {
    abort(429, 'You have used all of this month\'s exports.');
}

// $status->used, ->allowance (null: no limit), ->remaining(), ->exhausted()
```

On a route, `usage:exports` does what `usage:api-calls` does. An unknown meter name throws, so a typo is found at once and is not a way round the limit.

## What customers and staff see

- **Customers:** **Settings → Billing → Usage this month** shows each meter with a bar when there is a limit, what is left and when the count starts again, in the viewer's own time zone.
- **Staff:** **Billing → Metered usage** in the ACP (it needs `billing.acp.view`): a month picker, the totals, a chart of use each day, and the 25 accounts that used the most, with a flag for those at their limit. What an account may use is shown for the current month only, since an earlier month's allowance is not what it has now.

## The month

Usage is added up per **calendar month in the application's time zone** (`APP_TIMEZONE`, UTC by default), and the count starts again at the start of the next. It is not each subscription's own billing period: those start on the day someone subscribed, and Cashier does not store them (it asks Stripe each time). Nothing here bills by the period yet, and a calendar month is the same for everybody. `App\Support\Usage\UsagePeriod` is the one place that would change when the periods are lined up, which needs the dates from Stripe's subscription webhooks.

## Privacy and retention

- A use belongs to an account and **goes with it** when the account is deleted.
- It is in the account's **data export**, as a row a day and meter (not a row a use: an API user has thousands), in both files of the archive: `export.json` and, as rows of type `usage`, `export.csv`.
- Events are kept for **`BILLING_USAGE_RETENTION_MONTHS`** months (13 by default, in whole months; 0 keeps everything). `PruneUsageEvents` runs daily at 01:30 and needs the scheduler, like the other scheduled jobs. Every API call is a row; if you expect a very high volume, count into a cache counter and write one row a minute (`record()` takes a quantity).

## Billing the overage in Stripe (not built)

Stripe bills usage through a **metered price** on the subscription. This shop matches a subscription to its plan by its **one** Stripe price (Cashier leaves `subscriptions.stripe_price` empty for a subscription with several items), so putting a metered price on the subscription as a second item would make the plan stop matching and give nothing. Matching by item has to come first. It also cannot be checked without a Stripe account with a meter, so it was left alone rather than shipped untested.

The hook is there: `App\Events\UsageRecorded` is dispatched for every use that is counted (never for one that was already in the ledger). A listener could forward it to a Stripe billing meter with Cashier's `$user->reportMeterEvent($eventName, $quantity)`, which takes an `identifier` that Stripe uses to ignore repeats. Do it on a queue, not in the request. That sketch has not been run against Stripe.

## Troubleshooting

| What you see | What it means |
|---|---|
| An API caller gets a 429 *You have reached this month's limit* | They have used what their plan gives. **Billing → Metered usage** shows it; raise the amount on the plan (**Billing → Plans → Gives**), or set `BILLING_API_OVERAGE=allow` to stop refusing. It starts again at the start of the month. |
| A subscriber's billing page says *No limit on your account* | Their plan does not give the `api-calls` entitlement. On an existing site, run the plan seeder or set it by hand (see above). |
| Counts are lower than expected | A request that failed on the server's side or was rate limited is not counted, and a ledger that could not be written to is reported in the log (*the ledger*) and the request goes on. |
| The count starts again at the "wrong" time | The month is the site's calendar month (`APP_TIMEZONE`), shown in each viewer's time. |
| `usage_events` keeps growing | The scheduler is not running, so `PruneUsageEvents` is not. |

## Testing

`tests/Feature/Usage/UsageTest.php` covers counting, adding up, the month, allowances, the status and the pruning; `MeterUsageTest.php` the middleware (what counts, a request for something that is not there, the refusal and its headers, the new month, overage, a failing ledger, every token route being metered and the order it runs in); `UsageScreensTest.php` the billing page and the staff report; `UsageExportTest.php` the data export; and `tests/Feature/Database/UsageEventsMigrationTest.php` the table on every database.
