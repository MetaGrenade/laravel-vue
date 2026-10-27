# Warnings and bans

A moderator can **warn** a member, **ban** them (for good, or until a date), **lift** a ban, and **take down what they posted**, all from the admin area. Every one of them is kept in the member's history. The member is told of a warning or a ban, and can read what they were told in their account; they are not told that their posts were taken down.

> **Status:** built: warnings, bans with a reason and an optional end date, lifting a ban, taking down everything a member posted (alone, with a ban, and for several members at once), the history, telling the member (email and an account page), the page for a moderator, a link from forum reports, and banning several at once. **Not built:** see [below](#not-built). Automatic rules and the review queue are in [automod.md](automod.md).

## Using it

**Admin → Users → ⋯ → Moderate**, or, on **Admin → Forums → Reports**, **⋯ → Warn or ban the author** (which also attaches what was reported, so the history says what it was about). The page shows where the member stands, everything that has been done to them (newest first, with the staff note and who did it), and the buttons:

| | What it does |
|---|---|
| **Warn** | A notice with a reason. It is kept, and the member is emailed and can read it. **Nothing happens to their account:** it is not a penalty, and it never leads to a ban by itself. A moderator decides any ban. |
| **Ban** | Signs the member out and stops them signing in. A reason is required. It lasts until a moderator lifts it, or until the date given (through the end of that day, in the moderator's own time zone). |
| **Lift the ban** | The member can sign in again at once. The ban stays in their history, and a reason is optional. |

Each form has a **note for staff** (never shown to the member) and **Email them** (leave it off for a spam account). Reasons are 5 to 1000 characters; the member is told them in the words they were written.

To ban several at once, select them on **Admin → Users** and choose **Bulk actions → Ban users**: one reason (and optional end date) is given for all of them, each is banned by the same rules, and the page says who was skipped and why.

## Taking down what a member posted

For a spam wave, or a member who broke the rules, a moderator can take down **everything of theirs that is showing** in one go. The moderation page has a **What they have posted** card that says how much there is (threads, forum replies, blog comments, product reviews) and a **Take it all down** button. The same can be asked for:

- **with a ban**: tick *Also take down what they posted* on the ban form of the moderation page or of **Bulk actions → Ban users** (a spammer is banned and cleared out together; a member who was banned already is only cleared out);
- **for several members, without a ban**: **Bulk actions → Take down their posts**.

A reason is required (it is kept in each member's history, for staff), with an optional note for staff. **The member is not told.**

| Their… | …is |
|---|---|
| **Thread**, showing | **Unpublished.** The replies other members made in it go out of sight with it. A moderator can publish it again (*Forums → the thread → Publish*) and everything is back. |
| **Thread** waiting in the [review queue](automod.md) | **Rejected**, as the queue does: it was never public, and it is gone. |
| **Reply** in somebody else's thread (a held one too) | **Deleted**, as a moderator deletes a single reply: the row is kept, out of sight. |
| **Reply** in a thread they started | Left as it is: it is out of sight with the thread. |
| **Blog comment** | **Rejected** (the status a moderator gives one). It is no longer shown on the post or counted towards how popular it is. |
| **Product review** | **Rejected**, and marked as moderated by the moderator. It can be approved again in *Commerce → Reviews*. |

Nothing of anybody else's is touched, and what a moderator had already hidden, deleted or rejected is left as it was. The **reports** about what came down that were still waiting are marked as reviewed (by the moderator); a report somebody already dismissed stays dismissed. If there is nothing of theirs showing, nothing is done and nothing is written to the history.

It reaches the forum, the blog and the shop, so it is its **own permission**, `users.acp.cleanup`. The same rules apply as for a warning: nobody does it to themselves, and only an administrator does it to a member of staff (an administrator's posts can be taken down, unlike an administrator's account, which cannot be banned).

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

`moderation_actions` has a row for each warning, ban, lifting and clean-up: who, to whom, the reason, the note, the end date, what it was about (a copy of the title, so it still says when the post is gone), whether the member was emailed, and when. A clean-up (`removal`) also keeps `details`, how many threads, replies, comments and reviews came down and how many reports were closed; the history reads "hid 3 threads, removed 14 replies and rejected 2 comments". **Rows are written once and never changed.** They go when the member's account is deleted, and outlive the moderator who wrote them (shown as "A deleted account").

A clean-up is staff's business: it is **not** on the member's *Account standing* page and not in their data export, which have only what they were told (warnings, bans and their lifting).

The member's own row says where they stand now (`is_banned`, `banned_until`, `ban_reason`...), because everything that enforces a ban reads that.

Each request a moderator makes (the warning, the ban, the lifting) is also in the [audit log](audit-log.md) (`users.warn`, `users.ban`, `users.unban`).

## Permissions

| Permission | Lets a moderator |
|---|---|
| `users.acp.view` | Open the Users list and a member's moderation page. |
| `users.acp.warn` | Warn (new). |
| `users.acp.ban` | Ban, lift a ban, and ban several at once. |
| `users.acp.cleanup` | Take down what a member posted, alone, with a ban and for several members (new). A ban with *Also take down what they posted* needs both. |
| `users.acp.edit` | Open and save a member's account. Not given to moderators. |
| `users.acp.delete` | Delete an account. Not given to moderators. |
| `users.acp.verify` | Mark an email address verified. Not given to moderators. |
| `users.acp.update` | Link or remove a social account. Not given to moderators. |

The list, the account form, verifying an email, and a social account each check that permission on the route. A bulk action checks the permission for that action (verify, ban, clean-up, or delete).

Running `php artisan db:seed --class=RolePermissionSeeder` creates `users.acp.warn` and `users.acp.cleanup` and **gives the `moderator` role** `users.acp.view`, `users.acp.warn`, `users.acp.ban` and `users.acp.cleanup`. Administrators can do everything. Take them off a role in **Admin → Access control** if you do not want moderators to ban or to clear out members.

## Setting it up

1. `php artisan migrate` (a table for the history, three columns on `users`, and a `details` column on the history for clean-ups).
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
- **A button to undo a clean-up.** Nothing is destroyed for good (a thread is published again from the forum, a review approved again in *Commerce → Reviews*), but there is no screen that puts a whole clean-up back, and no list of exactly which items it touched. A rejected blog comment (the blog's comment screen lists only comments that have been reported) and a deleted reply are rows a developer can restore, not something a moderator can bring back from a screen.
- **Choosing what to take down.** It is everything showing, or nothing: by kind or item by item is not offered (a single reply or thread is still deleted, hidden or rejected from its own page).
- **Taking back reputation.** The points a member earned for what came down stay, as they do when a moderator deletes one reply.
- **Their signature, avatar and profile.** Only what they posted is taken down; the account's own fields are edited in **Edit account**.
- **Other kinds of content.** Support tickets are private and stay with the support team; polls, orders and wishlists are not posts.

## Testing

`tests/Feature/Moderation/ModerationTest.php`: what a warning does and does not do, a ban (for good and until a date, in whole seconds and UTC), what a banned member is told and when (not to somebody guessing at a password), a ban that lifts itself on sign-in, on a request, and by the job, the old copy of a member that must not lift a newer ban, lifting, who may moderate whom, what a warning is about, the page, the routes and their permissions, bulk bans, the link from reports, what the member can read, and the export. `tests/Feature/Mail/MailTextTest.php`: what a moderator typed in an email, against links, tags and formatting. `tests/Feature/Moderation/ContentCleanupTest.php`: what comes down and what is left alone (other people's, what was already down, a reply in their own thread), that a thread comes back when it is published again, the forum's cache, the reports, the history, that the member is not told (account page and data export), who may and who may not, the routes and their permissions, a ban with a clean-up, several members at once and the audit log. `tests/Feature/Moderation/ContentTakenDownTest.php`: the wording and the numbers.
