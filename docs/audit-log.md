# Admin audit log

Every change somebody makes in the admin area is written to an **audit log**: who made it, what it was done to, what changed (as it was and as it became), where from, and whether it worked. Staff read it in **Admin → Audit log**.

> **Status:** built: the log (append-only), recording for every change under `acp/`, before-and-after detail for accounts, roles, permissions, settings, plans, coupons, gift cards, orders, refunds and API tokens, the viewer with filters and a per-account view, retention, the permission, and every page staff open while [viewing as a customer](impersonation.md). **Not built:** recording staff actions made outside the admin area; a tamper-evident chain; export (see [not built](#not-built)).

## What is recorded

A **POST, PUT, PATCH or DELETE to an address under `acp/`**, by somebody who is signed in. One entry for each request, written when it ends.

- **It recognises the address, not the route group.** `AuditAdminActions` is in the `web` group and looks at the URL, so a route added to the admin area later is covered without anybody having to tag it. A test checks every admin write route is under it.
- **Looking at a page is not recorded.** (Only changes are, with one exception: every page staff open while [viewing as a customer](impersonation.md), which is the whole point of a view being on record.)
- **Not recorded:** a request nobody was signed in for (that is a bot, not an admin), and one that matches no route.
- **Recorded as *denied*:** somebody signed in who is refused (`403`), whether it is a member of staff without the permission or a customer trying an admin address. Somebody trying the admin area is worth knowing about.
- **Changes outside the admin area are not "admin actions"** and are not recorded: people signing up, webhooks, queued jobs, and staff acting through the public site (a moderator locking a forum thread from the forum's own page, say). See [not built](#not-built).

### An entry

| Field | What it holds |
|---|---|
| `actor_id`, `actor_label` | Who did it, and what they were called then (`Ada Admin (ada@example.com)`). Plain columns, not foreign keys: the log outlives the accounts it is about. |
| `event` | What was done, from the route's name without `acp.`: `users.ban`, `commerce.orders.refunds.store`. Plain names for the common ones are in `config/audit.php`. |
| `outcome` | `succeeded`; `failed` (it did not validate, ended with an error to read, or crashed: a `4xx` or `5xx`, or a redirect back with errors); `denied` (a `403`). Only what *this* request flashed counts: an error flashed by the request before says nothing about this one. |
| `subject_type`, `subject_id`, `subject_label` | What it was done to: the first record the address names, and what it was called then. |
| accounts (`audit_log_accounts`) | **Every** account it concerns, one row each (the person themselves, the owner of an order, the person a token is on): the account the address names, and the owner of each record that changed. A bulk action that bans fifty people concerns fifty accounts. This is what **Admin → Users → Audit log** filters on: everything done on one person's account, including as one of a bulk action. It does not stop at `max_changes`: the entry *describes* the first hundred changes but is in all fifty histories. |
| `changes` | What changed, see below. |
| `meta` | The route, method, path and status; the **names** (never the values) of the fields the request sent; the records its address names; how many changes were not kept. |
| `ip_address`, `user_agent` | Where from. |
| `created_at` | When. There is no `updated_at`: nothing is ever updated. |

## What changed

While an entry is open, the models listed under `models` in `config/audit.php` add what they changed to it, as it was and as it became:

```json
{ "model": "User", "id": 9, "label": "Troublemaker", "action": "updated",
  "old": { "is_banned": false, "banned_at": null },
  "new": { "is_banned": true,  "banned_at": "2026-10-15T12:00:00+00:00" } }
```

- **Values are read the way the model reads them** (a yes or no is a yes or no, not `1`), so before and after can be compared. A value is cut to `max_value_length` (300) characters.
- **A change to nothing but a timestamp is not an entry** (`ignore` lists what is not worth one, such as `last_activity_at`).
- **Secrets are hidden**, not written down: `password`, `two_factor_secret`, `two_factor_recovery_codes`, an API token's `token`, a gift card's `code_hash`. The change is still recorded (`"[hidden]"` both ways), just not the values. An attribute is also hidden by what it is called, whatever the model says: `token`, anything ending `_token`, and anything containing `password`, `secret`, `api_key` or `recovery_code`, so a secret added later is never written down. (`tokenable_id`, which says whose token it is, is not hidden.)
- **A request that changes a great deal** (a bulk action) keeps the first `max_changes` (100) and says how many it did not (`changes_not_kept`). The accounts it concerns are all kept, whether or not their change is among those described, so nobody's history leaves it out.
- **Nothing is recorded for a change made when no entry is open,** so the same model changed by a customer or a job is not an "admin action".

Listed by default: accounts (`User`), `Role`, `Permission`, `SystemSetting`, subscription plans, entitlements, coupons, gift cards, orders, refunds and API tokens. To add another, add its class to `models`.

### Roles and permissions

Giving somebody a role, or a role a permission, is not a change to a model, so it is recorded where it is done, through `app(Audit::class)->tracking($model, 'roles' | 'permissions', fn () => ...)`. It records the names that were **really added and removed**, not what was asked for: saving somebody with the roles they already have records nothing. (Spatie's own events report what was *requested*, which would make the log say things that did not happen.) A test fails if an admin controller changes roles or permissions without it.

### Entries made by code

`Audit::record($event, $actor, $subject, ...)` writes an entry for something that is not an admin request (a console command, a job) or is not a change. [Read-only impersonation](impersonation.md) uses it for the pages opened, the things refused and the end of a view.

## The viewer

**Admin → Audit log** (`audit.acp.view`) lists entries newest first, and nothing in it can change one: there is no route that does. Filters: search (who, what it was done to, the action, the address), the action, who did it, the result, a range of days (in the viewer's own time zone, both ends included), and rows per page. Each row opens to show **what changed** as a table of before and after, and the request (method, path, route, address, the field names it sent, the browser). **Admin → Users → Edit → Audit log** opens it for one account, with every entry that concerns that account, bulk actions included (an entry that concerned several says how many).

The permission is **view only**: `audit.acp.view` is the only one made, because nothing in the log is created, edited or deleted from the admin area. Admins have it (and reach the page without it); to give it to somebody else, give them `audit.acp.view`.

## Append-only

The model refuses to change or delete an entry (`LogicException`). That guards the application's own code. Whoever can write to the database can still change it, so for a log that is tamper-proof from the application's side:

- give the application's database user **no `UPDATE` or `DELETE`** on `audit_logs`, and
- leave `AUDIT_RETENTION_DAYS` at 0 and prune with a separate credential (the scheduled `PruneAuditLogs` runs as the application's user and would be refused).

### Retention

`AUDIT_RETENTION_DAYS` (default `0`: keep everything) is how long entries are kept. Above 0, the daily `PruneAuditLogs` job (02:00) deletes older ones in chunks, going to the table and not through the model. It is the only thing that deletes from the log.

## Privacy

An entry holds the **address and browser** of a member of staff, and the **nickname and email** of the people it is about, as they were then. They are kept for as long as retention says, and **a person's deletion or an erasure request does not remove them**: the log is there to show what staff did, which does not stop being true. Choose a retention you are happy to defend (two years is common), and say so in your privacy policy. The log is not part of a person's data export, since it is a record of staff's actions and not their own content.

## Upgrading

- Run `php artisan migrate` (one new table, `audit_logs`).
- Run `php artisan db:seed --class=RolePermissionSeeder` so the `audit.acp.view` permission exists and admins have it. Admins can open the page without it (they pass every permission check), but the **Audit log** link in the admin menu follows the permission, and other staff need it to be given.
- The log starts from the day it is deployed. Nothing before it was recorded.

## Not built

- **Staff actions outside the admin area**: a moderator acting through the forum's or the blog's own pages. They are authorised through their own routes and are not under `acp/`.
- **A tamper-evident chain** (each entry hashing the one before it, so that removing or editing one can be seen). The database privileges above are the protection today.
- **Export** of the log (CSV), and **alerts** on things such as a role being given or a run of denied requests.
- **Telling the person** that staff did something on their account.

## Testing

`tests/Feature/Audit/AuditLogTest.php` (what is recorded, outcomes, roles, settings, secrets, coverage), `AuditLogViewerTest.php` (who may look, filters, paging), `PruneAuditLogsTest.php`; `tests/Feature/Database/AuditLogsMigrationTest.php`.
