# Read-only impersonation ("view as")

A member of staff can see the site **as one customer sees it**, to help with a support case, without being able to change anything. Every page they open is written to the [audit log](audit-log.md).

> **Status:** built: starting a view (with a reason), the bar at the top of every page of it, a time limit, ending it (by hand, by signing out, by the clock, or because the account changed), read-only enforcement, the pages kept from view, and the audit trail. **Not built:** telling the customer (see [not built](#not-built)).

## Using it

**Admin → Users → Edit → View as this user** (needs the `users.acp.impersonate` permission, which admins have). You are asked **why** (a ticket number will do), and are then taken to the customer's dashboard with a bar across the top:

> Viewing as Jane (jane@example.com) · Read only: nothing can be changed. · Reason: Ticket 4411 · Ends in 27:31 · **Stop viewing**

**Stop viewing** (or signing out, or the clock running out) takes you back to the customer's page in the admin area.

**It applies to the whole browser session.** Every tab of that browser shows the customer's site until the view ends, the admin area included. Use a private window to keep working in the admin area meanwhile.

## Who can be viewed

**Customers only.** Not anybody with a staff role (`admin`, `editor`, `moderator`), not your own account, and not a banned account (a ban signs the account out, which would end the view). The page says why when it is one of those.

## How it works

The staff member stays signed in as themselves. The view is a few facts in their own session: who they are, whose account, why, and when it ends (`impersonation` in the session; see `App\Support\Impersonation\Impersonation`). The middleware `ApplyImpersonation` then makes each request the customer's:

- **The customer is swapped in for the request only** (`Auth::guard('web')->setUser()`) and the staff member put back before the session is saved. Nothing is logged in or out, so there is **no sign-in event, no "new device" email, and no entry in the customer's list of sessions** (the database session row keeps the staff member's id; a test checks it). Signing out of the staff session ends the view.
- **The browser's session is not the customer's.** The person signed in for the request is the customer, but the session (and its id) is still the staff member's. Anything that finds something by the session rather than the person has to leave it alone, or it would show the staff member's own things as the customer's. The one such thing is **the cart**: a guest's cart is tied to the browser session, and `CartManager::forRequest()` finds a cart by the account **or** the session and takes the newest. While viewing, a request carries the mark `Impersonation::isActive()` and the cart is found by the customer's account alone, so the customer's shopping state is what staff see (and a cart tied to the staff member's browser is never handed to the customer). If you add another lookup keyed on the session id, check `Impersonation::isActive($request)` there too.
- **It runs before authentication** (it is in the middleware priority list for that), so the sign-in check, per-person throttles, route bindings and the pages all see the customer.
- **Nothing can be changed.** Any request that is not a `GET` is refused (a `303` back to the page for a browser, a `403` with a message for a client that wants JSON). The way out, `POST impersonation/stop`, and `POST logout` (which ends the view instead of signing out) are the only exceptions.
- **Looking changes nothing either.** A `GET` runs inside a database transaction that is **rolled back**, however many transactions the page opened and left open, or whether it failed. What a page does as it is looked at (a counter bumped, a cart made, `last_activity_at`) is not kept.
- **Closed by default.** A `GET` is served only for a route listed in `config/impersonation.php` under `viewable`. The rest, and the whole of the admin area and the API (even a browser session's call to it), are refused. See the next section.
- **The staff member's powers do not carry over.** The request is the customer's, so the admin area is closed to it.

### Which pages

Everything a customer can open, **except** what is under `blocked` in `config/impersonation.php`, and why:

| Kept from view | Because |
|---|---|
| Security settings | The page sends the customer's decrypted **two-factor recovery codes**, any pending two-factor secret, and the ids of their other sessions. |
| Downloads (data export, invoices, ticket attachments, bought files) | A download is the customer's own data, or what they bought, and counts against their allowance. |
| Checkout, order completion, Tebex continue, Cashier payment | They call Stripe or Tebex and can change something there, which a rollback does not reach. |
| Sign-in, register, password reset, two-factor challenge, OAuth, verify-email link | Viewing as one person must not turn into being another. |
| Blog previews by link, live-update auth | Not pages a customer reaches by themselves, and a read-only view has no use for updates. |

The "verify your email" notice **is** viewable: it is where a customer who has not verified is sent, so it is what they see.

**A test fails when a `GET` route is in neither list**, so a page added later is decided on when it is added. Decide by asking: does it hand over a secret, change something outside the database, or sign somebody in? If so it goes under `blocked`.

## Time limit

**30 minutes** from the start (`IMPERSONATION_MINUTES`), fixed: it does not slide as you use it. The bar counts down; at zero the page reloads and the view ends. The server checks on every request whatever the page says. Starting again needs a new reason.

A view also ends, on the next request, when:

- the account is deleted, banned, or given a staff role (`account`);
- the staff member is banned or loses the permission (`permission`).

## What is recorded

All in the [audit log](audit-log.md), by the **staff member** (the actor) about the **customer** (the account, so they are in that account's history under **Admin → Users → Edit → Audit log**):

| Event | When | What it holds |
|---|---|---|
| `impersonation.started` | The view starts | The reason and the number of minutes. (It is the admin request's own entry, named for what it did.) |
| `impersonation.viewed` | Each page opened | The route, method and **path** (never the query string, which can hold tokens). Partial reloads, prefetches and `HEAD` requests are not pages opened. |
| `impersonation.blocked` | A page or a change was refused | The same, and the status. Recorded as **denied**. |
| `impersonation.stopped` | The view ends | How (`you`, `logout`, `expired`, `account`, `permission`), the reason, how many seconds, and how many pages. |

A page is **recorded before it is shown**, so a page that cannot be recorded is not shown (the log being down stops views, not the other way round). Ending a view is the opposite: it is never held up by the log, so nobody is kept in a view because an entry could not be written.

## Setting it up

- Run `php artisan db:seed --class=RolePermissionSeeder` so `users.acp.impersonate` exists (admins pass every permission check without it; the link for other staff follows the permission, which you give them).
- `IMPERSONATION_MINUTES` (default 30).
- **Queued jobs:** a `GET` that dispatches a job to a queue that is not the database (Redis, SQS) is not rolled back. Pages do not do this today. If you add one that does, keep it off the pages in `viewable`.

## Not built

- **Telling the customer** that staff looked at their account, by email or in their account. A view is in the audit log, not yet in front of the person it is about (the same item as in [audit-log.md](audit-log.md#not-built)).
- **Seeing a staff account as it sees things.** Staff accounts cannot be viewed as, by design.
- **A second, separate session** so a view does not take over every tab of the browser it was started in.

## Testing

`tests/Feature/Impersonation/ImpersonationTest.php`: starting (reason, who, permission), the swap and putting back (including the session row in the database), read-only enforcement, rollback of a page that writes, leaves a transaction open or fails, the pages kept from view (with a control that the security page does hand over recovery codes to the customer), ending every way, the audit trail, where the middleware sits, and that every `GET` route is decided on. `ImpersonationCartTest.php`: the customer's cart is shown, not one tied to the staff member's browser (with a control that a cart tied to a browser is still found as before).

A page prop must not be called `impersonation`: it would replace the shared prop the bar at the top of every page reads (the "view as" card on the users page is `viewAs`, and a test checks the users page leaves `impersonation` null).
