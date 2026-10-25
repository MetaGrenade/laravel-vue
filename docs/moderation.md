# Warnings and bans

A moderator can **warn** a member, **ban** them (for good, or until a date), and **lift** a ban, all from the admin area. Every one of them is kept in the member's history, the member is told, and they can read what they were told in their account.

> **Status:** built: warnings, bans with a reason and an optional end date, lifting a ban, the history, telling the member (email and an account page), the page for a moderator, a link from forum reports, and banning several at once. **Not built:** see [below](#not-built). Automatic rules and the review queue are in [automod.md](automod.md); clearing out what a spammer posted is the next slice of the community milestone.

## Using it

**Admin → Users → ⋯ → Moderate**, or, on **Admin → Forums → Reports**, **⋯ → Warn or ban the author** (which also attaches what was reported, so the history says what it was about). The page shows where the member stands, everything that has been done to them (newest first, with the staff note and who did it), and the buttons:

| | What it does |
|---|---|
| **Warn** | A notice with a reason. It is kept, and the member is emailed and can read it. **Nothing happens to their account:** it is not a penalty, and it never leads to a ban by itself. A moderator decides any ban. |
| **Ban** | Signs the member out and stops them signing in. A reason is required. It lasts until a moderator lifts it, or until the date given (through the end of that day, in the moderator's own time zone). |
| **Lift the ban** | The member can sign in again at once. The ban stays in their history, and a reason is optional. |

Each form has a **note for staff** (never shown to the member) and **Email them** (leave it off for a spam account). Reasons are 5 to 1000 characters; the member is told them in the words they were written.

To ban several at once, select them on **Admin → Users** and choose **Bulk actions → Ban users**: one reason (and optional end date) is given for all of them, each is banned by the same rules, and the page says who was skipped and why.

## What a ban does

- **The member is signed out** at their next request and cannot sign in. When they try (with the right password, so that somebody guessing at one is not told who is banned or why), they are told: *"Your account has been suspended until 3 Nov 2026, 23:59. The reason given: ..."*, or *"Your account has been banned."* for a ban with no end. `MODERATION_CONTACT` (an email or web address) is added as *"If you think this is a mistake, contact ..."* when it is set.
- **API keys are refused** while the ban lasts (with the same reason, as a `403`). They are **not deleted**: a ban can end, and a member's tools should work again when it does. (Before this, a banned member's API request ended in a server error, and a request that got further would have deleted the key.)
- **A ban with an end date lifts itself.** It is lifted the moment the member next tries to sign in or makes a request, so it ends exactly on time where it matters, and by a job that runs every minute (`LiftRunOutBans`, needs the scheduler) so that the lists of members are right and the email goes out. The lifting is in the history, as the system's doing.
- **Stored in whole seconds and in UTC**, whatever time zone the moderator is in: the end of a day is 23:59:59.999999, which MySQL would round up to the next day if it were stored.

## Who can be moderated

Nobody can moderate themselves. **An administrator cannot be banned at all** (take the role away first). **A member of staff** (`admin`, `editor`, `moderator`) **can only be warned or banned by an administrator.** The page says which applies when the buttons are not available.

## What the member is told

- **An email** for a warning, a ban and the lifting of a ban (`WarningIssued`, `AccountBanned`, `BanLifted`). They are account notices, so they are sent **whatever the member's notification settings say**, as a security notice is. What a moderator typed is shown as words, never as a link or formatting (`MailText`, tested against links, tags, emphasis, images and tables).
- **A page in their account**, *Settings → Account standing*, listing every warning and any ban they have been under, with the reason, the date and what it was about. It never shows the staff note or who the moderator was.
- A suspension that **ran out** emails the member only if they were emailed when it began.
- The warnings and bans are in the member's **data export** (reason and date, not the note or the moderator).

## What is kept

`moderation_actions` has a row for each warning, ban and lifting: who, to whom, the reason, the note, the end date, what it was about (a copy of the title, so it still says when the post is gone), whether the member was emailed, and when. **Rows are written once and never changed.** They go when the member's account is deleted, and outlive the moderator who wrote them (shown as "A deleted account").

The member's own row says where they stand now (`is_banned`, `banned_until`, `ban_reason`...), because everything that enforces a ban reads that.

Each request a moderator makes (the warning, the ban, the lifting) is also in the [audit log](audit-log.md) (`users.warn`, `users.ban`, `users.unban`).

## Permissions

| Permission | Lets a moderator |
|---|---|
| `users.acp.view` | Open the Users list and a member's moderation page. |
| `users.acp.warn` | Warn (new). |
| `users.acp.ban` | Ban, lift a ban, and ban several at once. |

Running `php artisan db:seed --class=RolePermissionSeeder` creates `users.acp.warn` and **gives the `moderator` role** `users.acp.view`, `users.acp.warn` and `users.acp.ban` (it had none of them). Administrators can do everything. Take them off a role in **Admin → Access control** if you do not want moderators to ban.

## Setting it up

1. `php artisan migrate` (a table for the history, and three columns on `users`).
2. `php artisan db:seed --class=RolePermissionSeeder`.
3. The scheduler (`php artisan schedule:run` every minute) and a queue worker, for the job and the emails.
4. Optionally `MODERATION_CONTACT` in `.env`.

## What changed for what was already there

- A ban now **needs a reason**, single or bulk (`reason`), and the quick *Ban* and *Unban* items in the Users list are a single **Moderate** item that opens the member's page.
- `PUT acp/users/{user}/ban` and `unban` are now handled by `UserModerationController` and need `users.acp.ban` on the route.
- Banning an administrator, or banning or warning yourself, is refused.

## Not built

- **A list of everything moderators have done** across all members (the audit log has each request; the history is one member at a time).
- **Points, or an automatic ban at a number of warnings.** A warning has no effect of its own, by design.
- **Warnings that expire**, or a member **acknowledging** one.
- **Appeals.** `MODERATION_CONTACT` says where to ask; there is no form.
- **Banning an email address or an IP address**, or a mute or a "can read but not post" state.
- **Warning several members at once.**
- **Reason templates** for the usual rules.

## Testing

`tests/Feature/Moderation/ModerationTest.php`: what a warning does and does not do, a ban (for good and until a date, in whole seconds and UTC), what a banned member is told and when (not to somebody guessing at a password), a ban that lifts itself on sign-in, on a request, and by the job, the old copy of a member that must not lift a newer ban, lifting, who may moderate whom, what a warning is about, the page, the routes and their permissions, bulk bans, the link from reports, what the member can read, and the export. `tests/Feature/Mail/MailTextTest.php`: what a moderator typed in an email, against links, tags and formatting.
