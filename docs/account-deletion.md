# Deleting an account

What happens to a member's data when their account is deleted, what the site keeps on purpose, and the one command to run after upgrading from a version that left rows behind.

## How an account is deleted

| Who | Where | What it asks |
| --- | --- | --- |
| The member | Settings → Profile → Delete account | Their password |
| Staff | Admin → Users → Delete (one account, or the **Delete** bulk action) | The `users.acp.delete` permission |

Both end the member's Stripe subscriptions first. If Stripe cannot be reached nothing is deleted and the member (or staff) is told to try again: an account that is gone but still being charged is worse than one that is still there. Then the account is deleted in the same request. Nothing is queued, so no worker is needed for it.

**An erasure request is not this.** The starting privacy text ([legal-pages.md](legal-pages.md)) tells members to ask on the privacy settings page, that a person handles the request and that it is answered within 30 days. That request is a status note a member of staff reads; deleting the account is still something staff do from Admin → Users. Nothing erases an account by itself.

## What goes with the account

**By the database.** Most of what a member owns has `user_id` with `ON DELETE CASCADE`, so the rows go in the same statement: their threads, posts, comments, votes, follows, tickets and the replies on them, data exports, usage, badges, warnings and bans against them, and so on. Their attachments on the forum are unlinked by `ForumAttachments::forget()`.

**By `App\Support\Privacy\MemberLeftovers`.** Two kinds of thing have no cascade, and used to stay for good:

| What | Why the database does not take it | What happens now |
| --- | --- | --- |
| **API keys** and **every request made with them** (the address, the fields sent, the answers) | `personal_access_tokens` belongs to a morph (`tokenable`), so it has no foreign key to `users` | The log is deleted in chunks of 5,000, then the keys |
| **Notifications** (`notifiable`), **saved addresses** and **entitlements** (`owner`) | The same: morphs | Deleted, for the member's morph type and id only |
| **Browser sessions** | `sessions.user_id` has no foreign key | Deleted when sessions are kept in the database (`SESSION_DRIVER=database`), so the member's other browsers are signed out at once; with another driver there is nothing to delete here |
| **A password reset that was asked for and not used** (`password_reset_tokens`) | The row is keyed by the member's address and has no foreign key. It holds the address and a hash of the token, and Laravel deletes it only when the reset is used or `auth:clear-resets` runs, which nothing did | Deleted by the address the account held, with the rest. The scheduler also runs `auth:clear-resets` every night (03:00), which clears every token that has run out (they last an hour), whoever it was for |
| **The Discord package's rows** (`discord_user_sync_states`, `discord_notification_preferences`, `discord_sync_failures`) | The package's tables have no foreign key | Deleted, if the tables exist |
| **The zip of a data export** | The row cascades; the file does not, because a cascade runs inside the database and fires no model event, so the `deleting` hook that unlinks a file never runs | Unlinked. Only a file inside `exports/` is taken: a row that names any other path is not the member's to delete (see [private-uploads.md](private-uploads.md#data-export-files)) |
| **The attachments of the member's own tickets** | The same | Unlinked. What a member of staff attached to somebody else's ticket stays with that ticket |
| **The covers of the posts the member wrote** | The posts cascade; their covers are files on the public disk | Every size of each cover, and every cover their revisions name, is unlinked, unless another post names the same file ([blog-covers.md](blog-covers.md#deleting-a-post)) |

The files are found **before** the account is deleted (the rows that name them go with it) and unlinked **after**. A disk that cannot be reached does not undo a deletion that has already happened: the error is reported and the rest carries on. So is a file a disk refuses to delete without throwing (the public disk is set not to throw, so it answers `false`): each one is reported with its name, so a directory the web server may not write to shows up in the log and not only as files that never go.

A row is the member's only when it names the member's **type** as well as the id. A key, notification, address or entitlement owned by something else that happens to have the same number (a team, in 1.1) is not touched.

## What is kept on purpose

| What | Why |
| --- | --- |
| **Orders, refunds, order events, invoices, billing webhook calls and dunning notices** | Tax and accounting law make a site keep them. The row stays and its `user_id` becomes empty. The starting privacy text says so (section 5), with the period left for you to fill in |
| **Subscriptions** | The same: they are the record of what was billed. They were ended at Stripe first, and hold a plan, dates and Stripe's identifiers, not a name or an email |
| **The admin audit log** | It shows what staff did, and that does not stop being true ([audit-log.md](audit-log.md#privacy)). Set `AUDIT_RETENTION_DAYS` to what you are happy to defend |
| **Replies and edits by staff on other people's records** | The text stays with the ticket, post or revision; the author is shown as removed (`user_id` is set to empty) |
| **Quoted content** | A reply that quotes the member belongs to the conversation of the person who wrote it |

Change any of these only after reading what your privacy policy promises. The starting text is a starting point and says so.

## Accounts deleted before this

An account deleted by an earlier version left its API keys and their request log, notifications, addresses, entitlements, sessions, an unused password reset and Discord rows behind. They name an account that no longer exists, and nothing else would ever delete them. Once, after upgrading:

```bash
php artisan metaforger:members:forget-leftovers --dry-run
php artisan metaforger:members:forget-leftovers
```

The first counts and deletes nothing; the second deletes. It finds the rows by their account **not being in `users`**: a guest's session (no user), and a row that belongs to another type of owner, are never taken. It deletes in chunks, so a large site is slow but does not hold a table for the whole of it, and running it again finds nothing.

**It cannot find the files** of accounts deleted earlier: the rows that named them went with the account. The zips of data exports are swept by the scheduler (`PruneDataExports`, every 15 minutes). The cover pictures of a deleted author's posts are found by `php artisan metaforger:blog:covers:prune`, which deletes the files in `blog-covers/` that no post names ([blog-covers.md](blog-covers.md#files-nobody-names)). The attachments of a deleted member's tickets are not found by anything; they sit in `support-attachments/` on the private disk, and the ones whose ticket no longer exists can be told apart by looking for directories named after a ticket id that is gone.

## Not done

- **Backups.** A backup taken before the deletion still holds the member until it is overwritten on its normal schedule. The starting privacy text says so (section 5).
- **A queue for a very large account.** Rows are deleted in chunks, in the request. A member with millions of request-log rows makes the request slow, not wrong. Nothing is known to need it yet.

## Adding a table of personal data

Decide what happens to it when the account goes, and write the decision down:

1. If the table has `user_id` with `cascadeOnDelete()`, the database does it. If it holds **files** the model unlinks in a `deleting` hook, add the files to `MemberLeftovers::filesOf()`: the hook does not run on a cascade.
2. If it names a member by a morph, or by a plain column with no foreign key, add a line to `MemberLeftovers::forget()` (deleting in chunks through `inChunks()`) **and** to `ofMembersWhoAreGone()` so the command can clean what already exists.
3. If it is kept on purpose, add it to the table above, with the reason.
4. Add it to `tests/Support/PopulatesAMember.php`. `DeletedMemberTest` deletes a member three ways (the model, the member from their settings, staff from the admin area) and counts every table before and after, so a table that is populated and not forgotten fails it.

## Testing

`tests/Feature/Privacy/DeletedMemberTest.php` and `ForgetDeletedMembersLeftoversTest.php`. They run on SQLite, and CI runs them on MySQL 8 and PostgreSQL 16 as well. The orphan queries are written to be the same on each: they take ids first and delete by id, because a delete with a limit and a subquery on the same table is written differently by every database.
