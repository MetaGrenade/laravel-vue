# Upgrading

How to move a site that is already running to a newer version of MetaForger without losing what is in it, and what to look at afterwards.

> **Status:** written on 8 November 2026, before any release has been tagged. Until 1.0.0 there is no tag to move between: a site moves from one commit of `main` to a later one, and the steps are the same. From 1.0.0 the CHANGELOG has a section for each release, and a release that needs more than [the usual deploy](deployment.md#every-deploy) says so under an *Upgrading* heading. [What was tried](#what-was-tried) says what has been run and what has not.

## The short version

1. **Back up** the database, `.env` and `storage/app` ([what to back up](deployment.md#files-mail-and-backups)).
2. **Read** the CHANGELOG from the version you run to the one you move to, and [what changes for the people who run the site](#what-changes-for-the-people-who-run-the-site).
3. **Rehearse** on a copy of the database if the site matters ([how](#rehearse-on-a-copy)).
4. **Deploy** with the list in [Every deploy](deployment.md#every-deploy). That list already runs the migrations.
5. **Once, after the migrations**, run the steps in [Once, after the migrations](#once-after-the-migrations) that apply to you. None of them is done for you.
6. **Check** with [Checking a deploy](deployment.md#checking-a-deploy) and [Checking that it worked](#checking-that-it-worked).

## Before you start

### Know where you are

There is no version number to read yet. The commit is the version:

```bash
git log -1 --format='%h %cd' --date=short
```

With the new code in place and `composer install` run, this lists what the upgrade is going to change in the database, and changes nothing:

```bash
php artisan migrate:status --pending
```

`php artisan migrate --pretend` prints the SQL of those migrations without running it. An empty list means the database does not change.

### The machine

- **PHP 8.4 or newer** (`composer.json`). Composer refuses to install on an older PHP, and the command line and PHP-FPM can be two different versions: check both.
- **Node.js 22.13 or newer** (`.nvmrc` names 24) wherever you run `npm run build`. A server that only receives the built `public/build` folder needs no Node, unless it serves [server-side rendering](deployment.md#server-side-rendering-optional).
- The PHP extensions in the README's [Prerequisites](../README.md#prerequisites).

### Compare `.env` with `.env.example`

A setting your `.env` does not have takes its default from `config/`, and [configuration.md](configuration.md) lists the default of every one. Reading the settings that are new to your file is still worth ten minutes:

```bash
comm -13 <(grep -oE '^[A-Z][A-Z0-9_]*' .env | sort -u) <(grep -oE '^[A-Z][A-Z0-9_]*' .env.example | sort -u)
```

That prints the names `.env.example` sets and your `.env` does not. Most of them are optional. The ones to look at first are in [the table below](#what-changes-for-the-people-who-run-the-site).

### Rehearse on a copy

Running the upgrade on a copy of the live database costs some of your time and shows what the live run is going to do: how long the migrations take, what the seeder and the commands report, and what the site looks like afterwards.

1. Restore your backup of the database into a **second** database, and your `storage/app` next to it. The move of support attachments works on those files; without them a rehearsal reports every attachment as missing.
2. Run the upgrade from a **separate checkout** of the code with its own `.env` that names the second database, on another machine or another folder. Not from the live checkout: a cached configuration (`bootstrap/cache/config.php`) ignores environment variables given on the command line, and `DB_URL`, when it is set, wins over `DB_DATABASE`. Either one can send `migrate` to the live database.
3. Before the first command, run `php artisan db:show` there and read the database it names.
4. Follow this page there, then sign in and click through it.

## The deploy

[Every deploy](deployment.md#every-deploy) is the whole procedure: check out the new code, install, build, `php artisan migrate --force`, `php artisan api:docs`, `php artisan optimize`, `php artisan queue:restart`. For an upgrade that spans several releases:

- **Put the site in maintenance mode** for the migrations (`php artisan down --refresh=15`, then `php artisan up`). While they run, the old code does not always work with the new tables, and the new code does not always work with the old ones.
- **Do not run `composer update`** on the server. `composer install` installs exactly the versions `composer.lock` names, which are the ones the tests ran against.
- **Never `php artisan db:seed`** without `--class` on a live site. The default seeder adds a demo account with a known password.
- **The migrations of `metagrenade/metaforge-discord`** (the Discord companion, a separate package) run with the rest: eight tables whose names start with `discord_`. A site that does not use Discord gets them empty and can ignore them ([discord-companion.md](discord-companion.md)).
- **On a large live PostgreSQL database**, two migrations build indexes and hold a write lock on their table while they do. Read [Running it on a live PostgreSQL database](indexes.md#running-it-on-a-live-postgresql-database) before the deploy and build the big ones by hand first.

## Once, after the migrations

The commands in this order, each explained below. Leave out what does not apply.

```bash
php artisan storage:link                                  # when public/storage does not exist
php artisan db:seed --class=RolePermissionSeeder --force  # read "Roles and permissions" first
php artisan support:attachments:privatize --dry-run       # how many files are still on a public disk
php artisan content:sanitize --dry-run                    # how many stored posts would change
php artisan content:sanitize
php artisan metaforger:members:forget-leftovers --dry-run # rows that belong to accounts that were deleted
php artisan metaforger:members:forget-leftovers
php artisan metaforger:blog:covers:prune --dry-run        # cover files that no post names
php artisan metaforger:blog:covers:prune
php artisan metaforger:forum:attachments:prune --dry-run  # forum attachment files that no attachment names
php artisan metaforger:forum:attachments:prune
```

### The storage link

Product pictures and blog covers are served from `storage/app/public` through the link `public/storage`. The installer makes it, so a site that was set up with the installer has it. Look:

```bash
ls -l public/storage
```

If it is not there, `php artisan storage:link` makes it. Without it every uploaded picture is a broken image.

### Roles and permissions

A permission that a release adds exists only once the role seeder has run on your site. The seeder is not run for you.

Administrators do not need it: they pass every permission check. Without it, though, the new permission is not in the list under **Admin → Access control**, so you cannot give it to an editor or a moderator, and the defaults below are not given. The pages for the features that add permissions say so ([audit log](audit-log.md#upgrading), [moderation](moderation.md), [impersonation](impersonation.md), [automod](automod.md)).

**It does more than add permissions**, and one part of that surprises people:

- It creates each permission that does not exist. One that exists is left alone.
- It gives the `admin` role every permission.
- It gives `editor` the three poll permissions and `moderator` the seven it is meant to have (`polls.acp.view`, `users.acp.view`, `users.acp.warn`, `users.acp.ban`, `users.acp.cleanup`, `forums.acp.view` and `forums.acp.review`). It never takes a permission away, but it **puts back** one you took away from those two roles on purpose.
- **It gives the `admin` role to the member with id 1** when that member does not have it. On a site whose first account is its owner, nothing changes. On a site where member 1 is somebody else, it makes that person an administrator. In the rehearsal member 1 was an ordinary member, and after the seeder was an administrator.

So, before you run it:

1. Look at member 1:

   ```bash
   php artisan tinker
   >>> App\Models\User::find(1)?->only('id', 'nickname', 'email');
   ```

2. Write down the permissions of `editor` and `moderator` under **Admin → Access control**, so that you can see what it put back.
3. Run it. If member 1 should not be an administrator, take the role away under **Admin → Users → Edit** afterwards. The next run of the seeder gives it back.

Run `RolePermissionSeeder` itself, not `BaselineSeeder`: the baseline also puts back support categories and badges that staff removed ([Running it again](install.md#running-it-again)).

### Support attachments

Support ticket files used to be on the public disk, where anyone with the address could fetch them. A migration moves them to the private disk (`SUPPORT_ATTACHMENT_DISK`, `local` unless you set it) as part of `migrate`. If a file cannot be moved, the migration does not fail: it writes a `critical` line to `storage/logs/laravel.log` that says how many are still reachable.

```bash
php artisan support:attachments:privatize --dry-run
```

It says how many attachments are not on the private disk. Run it without `--dry-run` to move them, and again after you fix the cause of any it could not. It reports four results: *moved*, *missing* (no file on the disk: the row is left as it is, on its old disk, and is counted again by the next run), *failed* (permissions or a disk error: the file stays where it is and **may still be public**) and *skipped*. A count that does not reach zero after a run is usually *missing* files, for example when the database was restored without `storage/app`.

### Stored text

Forum posts, blog posts and their revisions are cleaned when they are written. Text that was stored before that was so is cleaned by:

```bash
php artisan content:sanitize --dry-run     # say how many records would change
php artisan content:sanitize
```

A site whose text was all written since the cleaning began sees little or nothing to change. It rewrites only the bodies that differ from their cleaned form, so running it again changes nothing.

The cleaning stores `Don't` as `Don&#039;t` and `Q&A` as `Q&amp;A`, and a thread's *excerpt* (the start of its first post, shown on the board lists, in the feeds and in the API) used to be made from that without turning the entities back, so it showed them. `migrate` makes the excerpts that are there right: it decodes the entities in them, and in nothing else. A paragraph that was run into the next one when the excerpt was made stays so (`...6Second paragraph`), because the words are not in the text to be told apart; a thread made from now on is right. The text a search index holds is made from the same words, so with Meilisearch run `php artisan metaforger:search:reindex` once after `migrate`.

The database search keeps the words of a blog post as well. `migrate` adds `blogs.body_text` and fills it from the bodies that are there, 200 posts at a time; it leaves `body` as it is, and running it again fills in any post that has none. A post saved from then on keeps it up to date, and so does `content:sanitize`. `php artisan migrate:rollback` drops the column, so go back to the code from before this change first: the search of this one reads it.

### Search

Only with `SEARCH_DRIVER=meilisearch`. The index is a copy of the database, so it can always be made again:

```bash
php artisan metaforger:search:health
```

If it says a count is off or the settings are not the coded ones, run `php artisan metaforger:search:sync` and then `php artisan metaforger:search:reindex` ([search-index.md](search-index.md#deploying)). Saving a record is indexed on the queue when Meilisearch is on, so a worker has to be running.

A site that had `SEARCH_DRIVER=mysql_fulltext` searches the database now: that driver was removed, and any value other than `meilisearch` means the database.

### Blog covers

A cover uploaded before the sizes were built has one file and is shown at that size. `php artisan metaforger:blog:covers:upgrade --dry-run` says how many would be drawn again and without `--dry-run` it does so ([images.md](images.md#covers-already-on-the-site)).

Deleting a post, or the member who wrote it, used to leave every file of its cover on the public disk. They go with the post now, and one command deletes the ones that were left:

```bash
php artisan metaforger:blog:covers:prune --dry-run
php artisan metaforger:blog:covers:prune
```

It deletes the files directly in `blog-covers/` that no post and no revision names, and leaves any file younger than an hour. It says how many files and how much space, counts only what was really deleted, and warns about (and exits with a failure for) a file the web server may not delete. Run it once; nothing schedules it ([blog-covers.md](blog-covers.md#files-nobody-names)).

### Forum attachments

Deleting a forum board, or a whole category, used to leave every file kept with a reply in it on the private disk, because the threads and replies go with the board by the database, which runs nothing that deletes a file. They go with the board now, and one command deletes the ones that were left:

```bash
php artisan metaforger:forum:attachments:prune --dry-run
php artisan metaforger:forum:attachments:prune
```

It deletes the files in the attachment directory that no attachment names, and leaves a file younger than an hour and anything not named the way the forum names its files. It says how many files and how much space, counts only what was really deleted, and warns about (and exits with a failure for) a file the web server may not delete. Run it once; nothing schedules it ([forum-attachments.md](forum-attachments.md#files-nobody-names)).

### Search index

With `SEARCH_DRIVER=meilisearch`, deleting a forum board or category, a FAQ category or a member used to leave the threads, questions and blog posts that went with it in the index, because the database removes them and runs nothing that tells Scout. They are asked to go now, once the deletion has happened. The ones that were left stay until the index is rebuilt: the health card on **System** says how many documents are too many, and one command clears them (it flushes each index and imports what should be there, so run it when the site is quiet):

```bash
php artisan metaforger:search:reindex
```

The same command puts right the names that were changed before: renaming a member, a brand or a FAQ category now sends the documents that carry the name again (a member's threads and blog posts, a brand's products, the questions of a category), in jobs on the queue (`QUEUE_CONNECTION`), so unless it is `sync` a worker has to be running for the new name to reach Meilisearch ([deployment.md](deployment.md)). A document made before this carries the old name until the rebuild.

A scheduled blog post is now published, and sent to Meilisearch, when its time comes: the scheduler queues `PublishDueBlogs` every minute. Until now it waited for somebody to open the blog, a post or the posts in the admin area, and a post that was due and not yet looked at was missing from the search. Nothing has to be done for posts that are due when you upgrade: the first run publishes them. It needs the scheduler and a queue worker, which a site already has for the other timed jobs ([deployment.md](deployment.md)).

Nothing is needed with the database search ([search-index.md](search-index.md#what-is-listed)).

### Accounts that were deleted

Deleting an account used to leave rows behind that the database does not take with it: the API keys and the log of every request made with them (address, fields sent, answers), notifications, saved addresses, entitlements, browser sessions and the Discord package's rows. They name an account that is no longer there. From this version they are deleted with the account, and the command clears what accounts deleted before it left:

```bash
php artisan metaforger:members:forget-leftovers --dry-run
php artisan metaforger:members:forget-leftovers
```

It deletes in chunks and finds a row by its account **not being in `users`**, so it never takes a guest's session or a row owned by something that is not a member. Run it once; a second run finds nothing. It **cannot find files** (the rows that named them went with the account): a data export's zip is swept by the scheduler, and the attachments of a deleted member's tickets are not ([account-deletion.md](account-deletion.md#accounts-deleted-before-this)).

### Times typed in the admin area

A time typed into a box in the admin area was read as UTC by the server, though the box holds the administrator's own wall clock, and the edit pages filled the box from the stored moment in the browser's zone. Anybody whose browser is not on UTC met it: a post scheduled for 09:00 in Denver went out at 09:00 UTC (02:00 there), and opening the post and saving it again, without a change, moved the time by the browser's offset each time. It was the same for a poll's start and end, the expiry of an API key, and the dates on an export or an erasure that is marked as done. The browser now sends the moment the box names (`2027-03-10T16:00:00.000Z`) and the server keeps it in the site's zone (`APP_TIMEZONE`, UTC unless you changed it).

Rows that were saved before keep the hours they were saved with. Nothing can say which of them were typed in another zone, so nothing rewrites them. Look over what has a time set by hand and was made in the admin area, and open and save the ones that are wrong:

- scheduled blog posts that have not gone out yet (**Admin → Blog**, status *Scheduled*);
- polls with a start or an end that is still ahead (**Admin → Polls**);
- API keys with an expiry (**Admin → Tokens**), above all the ones that must not outlive a date.

A site that runs on UTC, and an administrator whose browser is on UTC, had no wrong times and need do nothing. The filters *From* and *To* of the request log on **Admin → Tokens** pick whole days and are read in the site's zone, as they were.

### Text that was longer than its column

Some form fields gave no limit on how long the text could be. SQLite saves any length, so nothing showed in development or in the tests, but on MySQL, MariaDB and PostgreSQL the database refuses what does not fit its column (`Data too long`) and the person got a 500 page. It was so for the description of a forum board or category, the name of a permission or a role, and the email address of a member made or changed in the admin area (all `varchar(255)`), and for a poll's description, a blog post's excerpt and author bio, the answer of a FAQ, a ticket reply template, a member's bio, the failure reason of a data export and the first message of a ticket opened from the support page or the API (all `text`, which holds 65,535 bytes: 16,000 characters is what it is sure to hold, at four bytes each).

The forms now refuse it and say how long the field can be: **255** characters for the first group, **16,000** for the second. Nothing stored changes and nothing has to be done: with the database in strict mode (Laravel's default) nothing longer than a column was ever saved. A script or client that sends longer text gets `422` with the field's message where it got a 500. `tests/Feature/Validation/StringRulesHaveALengthTest.php` fails when a rule that takes text has no `max:`, so a new field cannot go back.

One field is still open: the first post of a thread, on the site and through the API. A reply and an edit of any post are limited to 5,000 characters and the opening post is not, so a long opening post can be made and then cannot be edited without shortening it. How long an opening post may be is a choice, not a fix; the two places are named in that test.

### Numbers that were bigger than their column

Three kinds of field gave no upper bound on a number: the **order** of a FAQ question and of a FAQ category, and the **hourly and daily quota** of an API key made or changed in **Admin → Tokens**. SQLite keeps any number in an integer column, but MySQL, MariaDB and PostgreSQL refuse one the column cannot hold (`Out of range value`) and the person got a 500 page. An `int` column holds up to 2,147,483,647; the quota columns are unsigned (4,294,967,295 on MySQL and MariaDB) but PostgreSQL has no unsigned type and keeps them in an `integer`, so 2,147,483,647 is what a site on either database can hold.

The forms now refuse a bigger number and say what the field can be: an **order** between -2,147,483,648 and 2,147,483,647, a **quota** from 1 to 2,147,483,647. Nothing stored changes and nothing has to be done: with the database in strict mode (Laravel's default) nothing bigger than a column was ever saved. A script or client that sends a bigger number gets `422` with the field's message where it got a 500. `tests/Feature/Validation/NumberRulesHaveABoundTest.php` fails when a rule that takes a number has no upper bound, so a new field cannot go back. It lets through a number that is an id (a column named `user_id`, `ids.*`, anything checked with `exists:`) or a page number, which PHP already keeps inside 64 bits.

### Something other than a number where a record's number goes

An address that holds a record's number (`/acp/users/42/edit`, `/shop/packages/…`, `/api/v1/support/tickets/42`) now answers **404** for anything that is not that: only digits, no more of them than the biggest number a database holds (9,223,372,036,854,775,807). PostgreSQL refuses to compare a number column with letters, a decimal or a longer number, so the person got a 500 page and the log an error; on every database, a few addresses did the same for a number too long for PHP.

**Some addresses that opened a record no longer do.** `42.0`, `42.`, `+42` or a space beside the number opened record 42 on SQLite, MySQL and MariaDB, and PostgreSQL took `+42` and the spaces. MySQL and MariaDB also read the digits at the start of a value and drop the rest, so `/acp/users/42abc/edit` opened user 42. All of these are a 404 now. Leading zeros (`0042`) still open the record.

The form fields that pick a record by its number (a billing plan, a forum category, the customer or agent of a support ticket) answer `422` with the field's message for what is not a whole number, where PostgreSQL answered 500.

Nothing stored changes and nothing has to be run. For code of your own, see the two rows about ids in [the tests that tell you what you forgot](extending.md#the-tests-that-tell-you-what-you-forgot).

### Security headers on every answer

The security headers used to go only on what got as far as a page of the site. A page that was not found, a visitor sent to sign in, an expired form, the maintenance page of `php artisan down` and every answer of the API went out without them. Every answer the application gives carries them now, and an error page carries the content security policy ([security-headers.md](security-headers.md)). Nothing has to be run. If your web server was adding the same headers for the application's answers, take them out there, or they are sent twice. A page of a route of your own that is in neither the `web` nor the `api` group gets the headers and no policy.

### Stripe events sent to `/billing/webhook`

Laravel Cashier adds a webhook of its own at `/billing/webhook` (under `CASHIER_PATH`), beside the site's `/stripe/webhook`. The documentation never used it, and it is switched off: the address answers **404**. It checked a signature only when `STRIPE_WEBHOOK_SECRET` was set, so a site without one applied an unsigned event from anybody who knew a customer's Stripe id.

**Look at your endpoints in Stripe** (**Developers → Webhooks**, in test and in live mode). If the address of one ends in `/billing/webhook` (`php artisan cashier:webhook` makes one like that), Stripe's deliveries to it fail from now on. Change it to `/stripe/webhook`, tick the events in [payments.md](payments.md#2-the-webhook), and check that `STRIPE_WEBHOOK_SECRET` is that endpoint's signing secret. An endpoint at the old address got Cashier's handling only, so no invoices, no emails about failed payments and no paid shop orders came of it.

If a site that was open to the public had Stripe keys and no `STRIPE_WEBHOOK_SECRET`, compare the rows of `subscriptions` with the subscriptions in the Stripe Dashboard: one that Stripe does not know was not made by Stripe.

### Staff permissions and the administrator role

Somebody without the `admin` role could use a permission you had given them on an administrator. With `users.acp.edit`, `users.acp.update`, `tokens.acp.create` or `acl.acp.edit` alone they could become one or act as one, and so could anybody with `system.acp.view` through a Discord mapping, where the Discord bot is connected. `users.acp.delete` and `acl.acp.delete` could delete the account or the role. The role seeder gives all of these to `admin` only, so a site that never handed one out was not open to this.

Now only an administrator can give the role, or change the role, an administrator's account or API keys, or a Discord mapping of the role ([acp-permissions.md](acp-permissions.md#what-only-an-administrator-can-do)). A change on the Discord admin pages also needs `system.acp.edit`. Nothing has to be run.

**If somebody who was not an administrator ever held one of those permissions, check** that nothing was changed:

1. Who has the `admin` role:

   ```bash
   php artisan tinker
   >>> App\Models\User::role('admin')->get(['id', 'nickname', 'email']);
   ```

2. Each administrator's email address and linked sign-ins (**Admin → Users → Edit**) and API keys (**Admin → Tokens**).
3. **Admin → Discord mappings**: a mapping of `admin` made earlier is still there and still works. Delete it unless you meant it.
4. **Admin → Access control**: the roles still have their own names.
5. The [audit log](audit-log.md) has what was changed under `/acp`. Changes to Discord mappings were never recorded.

### Nicknames and email addresses that differ only by case

MySQL and MariaDB compare text without regard to case, so on them `Ada` and `ada` were already the same nickname and `Ada@Example.com` and `ada@example.com` the same address. SQLite and PostgreSQL compare exactly, so on them the check for "already taken" let both through: two members whose names differ only by case (one can pass for the other, `Admin` and `admin`), or two accounts for one mailbox.

The check now sets case aside on every database. Registration, the profile settings, **Admin → Members** (when a member is changed) and the subscribe form for a guest refuse a nickname or an address that is taken in other letters with the usual "has already been taken", and so do the nickname that is made up for a guest or for someone who signs in with a provider, and an address a provider hands over (which is then left out and a placeholder used, as for any taken address). Nothing stored changes and nothing has to be done. SQLite sets aside the 26 ASCII letters and no others, PostgreSQL every letter its collation knows.

A member who already has a case variant, on a site that grew some before this, can keep their name and save their settings; they cannot take another variant. To see who has one (the queries return nothing on MySQL and MariaDB, which could never hold two):

```sql
SELECT LOWER(nickname) AS name, COUNT(*) FROM users GROUP BY LOWER(nickname) HAVING COUNT(*) > 1;
SELECT LOWER(email) AS address, COUNT(*) FROM users GROUP BY LOWER(email) HAVING COUNT(*) > 1;
```

`tests/Feature/Validation/NamesAreComparedIgnoringCaseTest.php` fails when a rule uses `unique` for a nickname or an address, or a query matches one with `where('email', ...)`.

#### An address typed in other letters finds the account

The same difference made the lookups by address answer differently: `Ada@Example.com` typed for the account `ada@example.com` (a phone capitalises the first letter of what is typed) found it on MySQL and MariaDB and found nothing on SQLite and PostgreSQL, so the sign-in page, the API sign-in (`POST /api/v1/auth/token`) and the password reset refused it there, as did a provider that had verified the address, which made a second account for the same mailbox instead of recognising the first. They all find it on every database now:

- **Signing in and the password reset** go through a user provider of their own, `eloquent-ignoring-case` (`App\Support\Members\EmailIgnoringCaseUserProvider`, set in `config/auth.php`, `providers.users.driver`). **If your `config/auth.php` is your own file, set that driver** (it was `eloquent`): with the old one the page works as it did before and only the case is exact again.
- **The API sign-in and a provider that has verified the address** use `App\Support\Members\EmailLookup::find()`. Use it too in code of your own that finds an account by an address someone typed.
- The address as it was typed is tried first, so two accounts that differ only by case (the pairs the queries above list) each keep signing in by their own spelling. Any other spelling of those two names both, and **so finds neither**: no account is picked by guessing. A reset link is not sent either, and a provider's address makes a new account as it did for an address nobody had.
- Nothing is different for an address that was already typed exactly, and nothing stored changes. MySQL and MariaDB are asked once and no more.

`php artisan migrate` also adds `users_lower_email_index` on SQLite and PostgreSQL, an index on `LOWER(email)` that the second question uses (every wrong address typed asks it, so without the index each would read every member). `CREATE INDEX` holds a lock that stops writes to `users` while it is built, which is a moment on a site with tens of thousands of members; on a large one build it first without the lock (`CREATE INDEX CONCURRENTLY users_lower_email_index ON users (LOWER(email))` on PostgreSQL) and the migration finds it there ([indexes.md](indexes.md#the-address-typed-in-other-letters)). MySQL and MariaDB get no index.

## What changes for the people who run the site

Behaviour that was different in an earlier build and is different now. The CHANGELOG has the reasons.

| You notice | What it is | What to do |
|---|---|---|
| **Everyone is signed out once.** | Laravel 13 reads sessions as JSON (`config/session.php`). A session the earlier build wrote in PHP's format cannot be read. | Nothing. Say so, if you can. |
| **A cron line, a Supervisor program or a deploy script fails with "command not defined".** | Six commands were renamed with the project, with no alias: `metaforger:install`, `metaforger:queue`, `metaforger:search:reindex`, `metaforger:search:health`, `metaforger:search:sync` and `metaforger:blog:covers:upgrade`. The old names are in the CHANGELOG entry *The project is renamed MetaForger*. | Find every `artisan` call in your cron, Supervisor and deploy files and check each against `php artisan list`. `docker compose up` also makes new `metaforger-*` containers and a new database, and leaves the old ones alone. |
| **Emails are not sent; rows pile up in `jobs`.** | Every notification email is queued on a queue named `mail`. A worker started with `queue:work` and no `--queue` reads only `default`. | Start the worker with `php artisan metaforger:queue work` ([the queue](redis.md#the-queue)) and `php artisan queue:restart` after the deploy. |
| **A key for the API answers `403`.** | A key's abilities used to be free text that nothing checked. They are scopes now, and a key whose abilities name none of the site's scopes (for example `read`) can call nothing that needs one. | Fix them under **Admin → Tokens** ([Upgrading from before scopes](api-keys.md#upgrading-from-before-scopes)), then `php artisan api:docs`. |
| **A client that signs in over the API gets a key that only reads the profile, and it ends after 90 days.** | It used to get full access that never ended. | Have the client ask for the scopes it needs, or keep the old behaviour with `API_SIGN_IN_SCOPES=*` and `API_SIGN_IN_EXPIRY_DAYS=0` ([sign-in defaults](api-keys.md#upgrading-from-before-rotation-and-the-sign-in-defaults)). |
| **A key that staff revoked stops working.** | The Revoke button only recorded the revocation and nothing checked it, so a revoked key went on working. | Nothing. A key you revoked for good now really is revoked; one you revoked to restore later works again when you restore it. |
| **An editor or a moderator can no longer do something in the admin area.** | Changing billing plans, replaying a webhook delivery, deleting one member and banning a member each check their own permission now: `billing.acp.create`, `.edit` and `.delete`, `users.acp.delete`, `users.acp.ban` (and a ban needs a reason). | Give the permission under **Admin → Access control** to the roles that need it. Administrators are not affected. |
| **The failed deliveries on Stripe's webhook endpoint stop.** | The webhook answered `500` to every event that Cashier handles and to every one nobody handles. It answers `200` now, and a renewal that needs the customer's bank no longer ends in an error. | Check that the endpoint receives the events the site needs ([Webhook events](billing.md#webhook-events)). `CASHIER_PAYMENT_NOTIFICATION` is unset by default, as in Cashier. |
| **Links in emails say `403 Invalid signature`, or the site sends no `Strict-Transport-Security` header.** | The site sits behind a proxy or load balancer that ends HTTPS and does not trust it. | Set `TRUSTED_PROXIES` ([Behind a proxy or a load balancer](deployment.md#behind-a-proxy-or-a-load-balancer)). |
| **Checkout asks for a shipping address.** | Products are shipped by default. A shop with no shipping zones still ships anywhere at no charge. | See the notes on the shop in [commerce.md](commerce.md#upgrading-an-existing-installation). |
| **The cart in the header shows only a subtotal.** | The estimate of 7% tax and a flat shipping charge it used to show was never real. | Nothing. |
| **Search is saved on the queue.** | With Meilisearch, `SCOUT_QUEUE` is on unless you set it. | Keep a worker running, or set `SCOUT_QUEUE=false` ([search-index.md](search-index.md#when-meilisearch-is-down)). |
| **The cookie banner has new words.** | It names the theme and menu cookies, which are set whatever was chosen on the banner. | Read your own cookie policy: if it was written before this, it may not say so. |
| **The `social_accounts` table loses four columns.** | A sign-in with Google or Discord no longer keeps the provider's access token, refresh token or raw profile (`access_token`, `refresh_token`, `token_expires_at`, `data`), since nothing used them. `migrate` empties and drops them. | Nothing, except a backup made before it still holds the tokens: keep it private or delete it ([What is kept from a provider](social-login.md#what-is-kept-from-a-provider)). |
| **Members are asked for their password to turn two-factor off or to make new recovery codes, and some cannot unlink a provider.** | Both Security-page buttons take a protection away, so they ask for the password first (good for `AUTH_PASSWORD_TIMEOUT`, three hours unless changed). Unlinking Google, Discord or Steam is refused when the member would have no other way to sign in: no other provider that is on, and no real, verified email address. Staff can still do it with **Disconnect anyway**. | Nothing to run. Members who signed up with a provider and never chose a password use **Forgot your password?** first; those with a made-up `…@oauth.local` address cannot turn two-factor off from the site ([Turning it off](two-factor.md#turning-it-off), [Unlinking and the last way in](social-login.md#unlinking-and-the-last-way-in)). |
| **Staff can switch two-factor off for a member who is locked out.** | A member who lost their authenticator app and recovery codes had no way back in from the site. **Admin → Users → Edit** has a *Two-factor authentication* card (a reason is asked for and kept in the audit log, and the member is emailed), and `php artisan metaforger:two-factor:reset <id or email>` does it on the server for the case where the only administrator is the one locked out. It is a new permission, `users.acp.reset-two-factor`, which administrators have and nobody else is given. | Nothing to run for administrators (the admin role passes every check). For a support team, run `php artisan db:seed --class=RolePermissionSeeder --force` (read "Roles and permissions" above first) or add the permission to their role under Admin → Roles and permissions ([When a member is locked out](two-factor.md#when-a-member-is-locked-out)). |
| **Setting two-factor up asks for the password.** | Turning it on looks harmless, but from a browser that was left signed in it puts somebody else's authenticator on the account, and the owner is locked out at the next sign-in (staff cannot switch it off from the site). **Enable multi-factor authentication** and the 6-digit confirmation now ask for the password first (`/confirm-password`, good for `AUTH_PASSWORD_TIMEOUT`); cancelling an unconfirmed setup does not. | Nothing to run. A member whose address the site made up (`…@oauth.local`) is not asked, and a member who signed up with a provider and has a real address is, so they set a password first with **Forgot your password?** ([Turning it on](two-factor.md#turning-it-on)). |
| **The Security page no longer carries the recovery codes on every load.** | It sent all of them, decrypted, with every visit, so a browser left signed in had the whole set. It now says how many are left and carries the codes for one load only: right after a set was made, and after the member asks (**Show recovery codes**, which asks for the password first). | Nothing to run. A member with no password of their own (a provider sign-up) sees the first set once and cannot ask again ([Seeing the recovery codes](two-factor.md#seeing-the-recovery-codes)). |
| **Changing a password signs the member's other browsers out.** | The site did not do this before: a browser left signed in stayed in for ever after the password was changed or reset. It does now, on any session driver, and Settings → Security has a **Sign out other browsers** button (it asks for the password). On a site that does not keep sessions in the database, the list of open sessions is gone from that page, because it was never true there. | Nothing to run, and **nobody is signed out by the upgrade**: a session stores its fingerprint of the password at its next request. A member's "remember me" cookie from before a password change stops working. API keys are not revoked by a password change ([Browser sessions](two-factor.md#browser-sessions)). |
| **Changing the email address asks for the password, and the old address is emailed.** | The address is where password reset links go, so a browser that was left open must not be enough to change it. **Settings → Profile** shows a "Your password" field when the address typed is another one; the address the account had is sent a notice in the member's language. A member whose address the site made up (`…@oauth.local`) is not asked. A member who signed up with a provider and never chose a password cannot pass it until they set one with **Forgot your password?**. | Nothing to run. The notice is queued on `mail`, so the worker you already need sends it. Wrong guesses share one limit of five a minute with the password page, **Sign out other browsers** and deleting the account ([Changing the email address](two-factor.md#changing-the-email-address)). |
| **Making or rotating an API key asks for the password.** | A key goes on working after the session that made it ends, so a browser that was left signed in must not be enough to make one (`POST /api/v1/auth/token` always asked). The **Create key** and **Rotate** dialogs in **Settings → API keys** show a "Your password" field. A member whose address the site made up (`…@oauth.local`) is not asked; a member who signed up with a provider and never chose a password sets one with **Forgot your password?** first. | Nothing to run. Wrong guesses share one limit of five a minute with the password page, **Sign out other browsers**, the email change and deleting the account. Revoking, removing and limiting keys are unchanged. Keys staff make in **Admin → Tokens** are unchanged too ([Asking for the password](api-keys.md#asking-for-the-password)). |
| **`BROADCAST_FORCE_LOG=true` keeps the log, whatever else is set.** | It was only read when `BROADCAST_CONNECTION` was left out or `log`, so next to `BROADCAST_CONNECTION=pusher` (what `.env.example` sets) and the Pusher keys it did nothing, and a staging site that was meant not to push still pushed to real people. It now wins over the connection and the keys. | If you set it to `true` and the site was in fact pushing, it stops: that is what it was for. Check with `php artisan config:show broadcasting.default` (it says `log`). A site that never set it, or set it to `false`, behaves as before ([realtime.md](realtime.md#setting-it-up)). |
| **Back after signing out no longer shows a new API key or a two-factor secret.** | The browser keeps every page it showed, for as long as the tab lives, and the page that showed a new key, the secret of a two-factor setup or the recovery codes was kept in the clear: `INERTIA_ENCRYPT_HISTORY` was off, and even on it was never thrown away at sign-out. Those pages now say their copy is encrypted, and signing out, deleting the account or being banned has the browser discard the key. | Nothing to run. The browser can only encrypt on HTTPS or `localhost`: on plain HTTP the copy is still kept in the clear (the console says so), so serve the site over HTTPS. `INERTIA_ENCRYPT_HISTORY=true` still encrypts every page. A session that runs out by itself is not a sign-out, so the key stays until the tab is closed ([Seeing the recovery codes](two-factor.md#seeing-the-recovery-codes)). |
| **A sign-in provider with no credentials no longer has a button.** | All three providers were on by default and each had a button whether or not the site had set it up, so a fresh site's sign-in page offered three that ended at the provider's own error page. A provider now needs its credentials as well as its switch: the client id and the client secret for Google and for Discord, the API key for Steam. Without them there is no button (not on the sign-in page, the registration page or **Settings → Security**) and the provider's `/auth/oauth/<provider>/…` addresses answer 404. `GOOGLE_REDIRECT_URI` and `DISCORD_REDIRECT_URI` became optional (the site uses its own callback address), and `STEAM_REDIRECT_URI` is gone, because nothing read it. | Look at the sign-in page. A provider you use whose button went is missing a setting: **Admin → System settings → OAuth Providers** names it. Steam sign-in used to work without `STEAM_API_KEY` (members got a generic nickname and no avatar); it wants the key now. A member who has only a provider that is no longer set up cannot use it to sign in, and the unlink guard counts it that way ([social-login.md](social-login.md#setting-up-a-provider)). |
| **A thread's excerpt, a mention email, a quoted reply and a search result show an apostrophe as an apostrophe.** | They showed `Don&#039;t` and `Q&amp;A`: the text was made from the stored HTML with `strip_tags`, which leaves the entities, and a quoted reply escaped them a second time into the new post. Paragraphs and list items were run together as well (`6Second paragraph`). `migrate` decodes the entities in the excerpts that threads already have. | With Meilisearch, `php artisan metaforger:search:reindex` once ([Stored text](#stored-text)). A reply that quoted a post earlier keeps what it was stored with. |
| **A search for `100%`, `a_b` or `_` finds what has those letters, not everything the pattern fits.** | In a `LIKE`, `%` is "anything" and `_` is "any one character": a search for *100%* found everything with *100* in it, *_* found every row, and *a_b* found *axb*. The support desk and the board search escaped them with a backslash, which SQLite does not take (a search for *a_b* found nothing there). The site search, the board search, the mention list, the blog list, the shop, the member directory, the support desk and the search box of every list in the admin area now read what is typed as letters, with `!` as the escape character, on all three databases; case still does not matter. See [search-index.md](search-index.md#what-is-listed). | Nothing to run. |
| **A member with a space or a letter such as `ë` or `Ł` in the nickname can be mentioned, and a mention is the member it shows.** | The server read a mention as `@` and ASCII letters, digits, `_`, `.` and `-` only, though a nickname can be anything: `@Ada Lovelace` named the member `Ada` (a different one, if there is one), `@Zoë` named `Zo`, and `Thanks @Ada.` named `Ada.`, which nobody is. The editor writes the exact name into the mention it makes (`data-nickname`), and that is what is read now; text that is typed takes letters and digits of any language, and the `.` or `-` that ends a sentence is not part of the name unless a member has one. See [Who a post mentions](notifications.md#who-a-post-mentions). | Nothing to run. Posts saved before keep the mentions they have; an edit looks for them again. |
| **A mention that starts a paragraph is a mention, and text made from a post keeps its paragraphs apart.** | The `@nickname` of a post was looked for in its text with the tags taken out, and the editor puts no space between `</p><p>`, `</li><li>` or `<br>`: in `<p>Thanks</p><p>@Ada can you look?</p>` the at sign followed the *s* of *Thanks* and was not the start of a name, so nothing was recorded and nobody was told. The same join was in the description of a page made from a post (a blog post with no excerpt: `IntroFirst paragraph`), a legal page, a Tebex package, the excerpt of a reply on a profile, in **Following** and on the leaderboard, and the summary column of a post in `export.csv`. All of them are made with `App\Support\Text\PlainText` now. | Nothing to run. A reply that was saved before keeps the mentions it has; an edit of it looks for them again. |
| **The search of the blog on the database driver looks for the words of a post, not its HTML.** | It looked in `blogs.body`, which is HTML with its characters as entities: a search for *don't* found nothing in a post that says so, and *https*, *href*, *noopener*, *blockquote* or *039* found every post with a link, a quote or an apostrophe, with nothing to mark. A post's words are now kept in a new column, `blogs.body_text`, which the model fills when the body is saved. | `php artisan migrate` adds the column and fills it from the posts that are there. Nothing else to run; Meilisearch already held the words. Anything of yours that writes `blogs.body` with a raw `UPDATE` has to write `body_text` too ([Stored text](#stored-text)). |
| **Making a card ready, saving one, choosing one, removing one, cancelling and resuming on the billing settings are limited.** | Each is a call to Stripe, and the first two are what somebody checking stolen card numbers repeats ("card testing"); every other billing action already had a limit and these six had none. Making the payment form ready and saving a card share ten a minute and sixty an hour per person; the other four share the `billing` limit of ten a minute with starting a subscription and opening the portal. A person who goes over is told to wait a while. | Nothing to run. A member who opens the two payment pages more than ten times in a minute is the only one who will see it. See [billing.md](billing.md#how-a-customer-subscribes). |
| **The log of API requests keeps 30 days.** | Every request made with an API key is a row in `token_logs` (what **Admin → Tokens** lists), and nothing deleted one. A daily job, `PruneTokenLogs` (02:30), now deletes rows older than `API_TOKEN_LOG_RETENTION_DAYS` (30), 5,000 at a time, so **the first night after the upgrade removes everything older than that.** `migrate` also adds two indexes to the table (a second and a half on a million rows on MariaDB). A key's hourly and daily limits are not affected. | To keep the log longer, or for ever (`0`), set `API_TOKEN_LOG_RETENTION_DAYS` before the first night. The job runs on the queue, so the scheduler and a worker must be running ([the queue](redis.md#the-queue)). See [The request log](api-keys.md#the-request-log). |
| **The zip of a data export is deleted 30 to 45 minutes after it is ready.** | The member is told the link works for 30 minutes, but the file stayed on the disk until somebody asked for the export again, and for ever if the member never came back or deleted their account. `PruneDataExports` (every 15 minutes, on the queue) now deletes the file of an export whose time is over, and any file in `storage/app/private/exports/` that no export names and that is over an hour old, **so the first run deletes the ones an earlier version left behind.** The row stays, with no file. In **Admin → Trust and safety**, the file of an export you mark finished has to be a file directly in `exports/` (it accepted any private file before); an export saved earlier with another path is not served, and its file is never deleted (the row only forgets the path). | Nothing, except that the scheduler and a worker must be running ([the queue](redis.md#the-queue)). A zip you meant to keep must be copied out of `exports/` before the first run. See [Data export files](private-uploads.md#data-export-files). |
| **Deleting an account deletes its API keys, the log of their requests, notifications, saved addresses, entitlements, sessions and Discord rows, and the files that are the member's.** | These had no foreign key to the account (they belong to a morph, or to a package), so they stayed for ever: the address and the answers of every request a deleted member's key had made among them. A data export's zip and the attachments of the member's own tickets were not unlinked either, because a cascade runs no model event. Orders, invoices and payment records stay on purpose. | Run `php artisan metaforger:members:forget-leftovers` once for the accounts deleted before ([Accounts that were deleted](#accounts-that-were-deleted)). Staff who delete a member whose key has a very long log wait a little longer: the log is deleted 5,000 rows at a time. See [account-deletion.md](account-deletion.md). |
| **Deleting a blog post, or the member who wrote it, deletes the files of its cover.** | The row went and every size of the cover stayed on the public disk for good, as did the covers its revisions named, and any cover that was written and then not saved. The path of a cover is a value in a row, so only a file directly in `blog-covers/` is ever deleted, and not one that another post names. | Run `php artisan metaforger:blog:covers:prune --dry-run`, then without `--dry-run`, once, to clear the files that were left ([Blog covers](#blog-covers)). Nothing schedules it. See [blog-covers.md](blog-covers.md#deleting-a-post). |
| **Deleting a forum board or category deletes the files kept with the replies in it.** | The threads and replies went with the board by the database, which runs no model event, so every picture and file on them stayed on the private disk for good, including those on replies that were held for review. A file the disk refuses to delete is now reported with its name instead of being passed over in silence, for one attachment as much as for a board. | Run `php artisan metaforger:forum:attachments:prune --dry-run`, then without `--dry-run`, once, to clear the files that were left ([Forum attachments](#forum-attachments)). Nothing schedules it. See [forum-attachments.md](forum-attachments.md#deleting-a-board). |
| **Deleting a board, a category, a FAQ category or a member takes their threads, questions and blog posts out of the search index.** | The database removes them by `ON DELETE CASCADE` and runs no model event, so Scout was never told: with Meilisearch the documents stayed for good, the search counted them (a page could come up short and the pages could run past the last result), the health card said the index held more than it should, and a deleted member's words stayed in it, in their own threads and in the threads of others that held a reply of theirs. The model now finds the keys before the rows go and asks the index to forget them once the deletion has happened; a thread of somebody else is made again without the member's replies. If Meilisearch is down at that moment the failure is reported and the deletion stands. | Run `php artisan metaforger:search:reindex` once if you use Meilisearch and have deleted any of these ([Search index](#search-index)). With the database search there is nothing to do. See [search-index.md](search-index.md#what-is-listed). |
| **A time typed in the admin area is the time on the administrator's clock.** | A scheduled post, a poll's start and end, an API key's expiry and the dates of an export or an erasure were kept as typed, with the zone dropped (read as UTC), and the edit pages showed them in the browser's zone, so saving a form untouched moved the time. Rows saved before keep their hours. | Look over the scheduled posts, the polls and the keys that have an expiry ([Times typed in the admin area](#times-typed-in-the-admin-area)). |
| **A description, a name, an email address or a text that is longer than its column is refused with a message.** | The rules gave no limit, so on MySQL, MariaDB and PostgreSQL the database refused the value and the person got a 500 (forum board and category descriptions, permission and role names, a member's email in the admin area, poll descriptions, excerpts, bios, FAQ answers, reply templates, the first message of a ticket). They say 255 or 16,000 characters now. | Nothing. A client that sends more gets `422` ([Text that was longer than its column](#text-that-was-longer-than-its-column)). |
| **A FAQ order or an API key quota that is too big for its column is refused with a message.** | The rules gave no upper bound, so on MySQL, MariaDB and PostgreSQL the database refused the number and the person got a 500. An order is limited to 2,147,483,647 (and down to -2,147,483,648) and a quota to 2,147,483,647 now. | Nothing. A client that sends more gets `422` ([Numbers that were bigger than their column](#numbers-that-were-bigger-than-their-column)). |
| **A nickname or an email address that differs from another only by case is refused on PostgreSQL and SQLite too.** | MySQL and MariaDB already did, because their collations ignore case; the other two compared exactly and let `Ada` and `ada` both in. | Nothing. To see whether a site already has two, run the queries in [Nicknames and email addresses that differ only by case](#nicknames-and-email-addresses-that-differ-only-by-case). |
| **An address typed in other letters than the account holds signs in on PostgreSQL and SQLite too.** | `Ada@Example.com` for `ada@example.com` found the account on MySQL and MariaDB and found nothing on the other two: the sign-in page, the API sign-in, the password reset and a provider that has verified the address. They now answer the same on all four. `config/auth.php` names a new user provider, `eloquent-ignoring-case`, and `migrate` adds an index on `LOWER(email)` on SQLite and PostgreSQL. | Nothing, unless `config/auth.php` is your own file: set `providers.users.driver` to `eloquent-ignoring-case` ([An address typed in other letters finds the account](#an-address-typed-in-other-letters-finds-the-account)). |
| **The scheduler now clears expired rows of the database cache every hour and the unused password reset tokens every night.** | A database cache (`CACHE_STORE=database`, the default) deleted a row that had run out only when its key was asked for again, so the rows the rate limiters leave (two for every failed sign-in, whatever address was tried) stayed for ever; and `auth:clear-resets`, the only thing that deletes a reset token that was never used, was never run, so the address of a member who asked and then deleted their account stayed in `password_reset_tokens`. `PruneExpiredCacheEntries` (hourly) and `auth:clear-resets` (03:00) are in `bootstrap/app.php`; deleting an account also deletes its token row. | Nothing, if the scheduler and a worker run ([deployment.md](deployment.md#what-a-live-site-is-made-of)). **The first run deletes everything that has run out**, which on a site that has been up for a year may be a large table: it goes 1,000 rows at a time. For accounts deleted before, `php artisan metaforger:members:forget-leftovers` now finds their reset token too ([account-deletion.md](account-deletion.md#accounts-deleted-before-this)). |
| **A call to Meilisearch now gives up after 5 seconds on a web request and 30 in a worker or command.** | Scout's client had no limit, so a Meilisearch that took the connection and said nothing (out of memory, a firewall that drops packets) held every request that searched for as long as it stayed silent, and the circuit that falls back to the database never opened because the call never failed. Measured: a server that accepted and never answered held a call for the 40 seconds it was kept. Now such a call fails, search is served from the database and the circuit opens. | Nothing. Raise `SEARCH_CONSOLE_TIMEOUT` if a rebuild's calls are cut off on a slow link to Meilisearch; `SEARCH_TIMEOUT`, `SEARCH_CONNECT_TIMEOUT` and `0` (no limit) are in [configuration.md](configuration.md) and [search-index.md](search-index.md#when-meilisearch-does-not-answer). A `config/search.php` you edited does not need the `http` block: without it the defaults apply. |
| **The `smtp` mailer now gives up on a server that does not answer after 15 seconds at each step.** | `config/mail.php` had `'timeout' => null`, which is PHP's `default_socket_timeout`, 60 seconds. A mail sent to an SMTP server that took the connection and said nothing held the request for 60.6 seconds (measured), and the two emails Laravel sends inside the request, to verify an address and to reset a password, are sent that way. Now the send fails after the limit, as a refused one does: a `failover` mailer goes on to the next mailer and a queued email is tried again. | Nothing. `MAIL_TIMEOUT` changes the number (`0` gives PHP's 60 seconds back, it is not "no limit"; empty is the default, 15), see [configuration.md](configuration.md) and [deployment.md](deployment.md#files-mail-and-backups). A `config/mail.php` you edited keeps its `'timeout' => null`, so nothing changes for it until you use `env('MAIL_TIMEOUT', 15)` there. |
| **The scheduler now deletes the record of a failed job after 30 days.** | Laravel keeps a row in `failed_jobs` for every job that gives up (the job and its exception with its stack trace, about 12 KB measured), and `queue:prune-failed`, which deletes them, was never run, so the table only grew. `PruneFailedJobs` (03:30, in `bootstrap/app.php`) deletes rows older than `QUEUE_FAILED_RETENTION_DAYS` (30). | **The first night deletes every failed job older than 30 days**, and `queue:retry` cannot bring those back: retry what you want to keep, or set `QUEUE_FAILED_RETENTION_DAYS` (`0` keeps them for ever) before then. The job runs on the queue, so the scheduler and a worker must be running ([the queue](redis.md#the-queue)). A `config/queue.php` you edited has no `retention_days` under `failed`, and the job then keeps 30 days. |
| **A page a signed-in member opens no longer reads every notification they have ever had.** | The header counts the member's unread notifications and takes the newest ten on every page, and the table's only index found the member's rows and not the unread ones among them, so both questions read every notification the member had (a read one is never deleted) and the second sorted them. Measured on MariaDB with 382,105 rows: 134 ms for a member with 40,000 read notifications, 0.8 ms now; 137 ms for one with 40,000 unread, 8 ms now. `migrate` adds the index `notifications_unread_by_time`. | Nothing. The index takes 0.5 s to build on 382,105 rows (MariaDB) and holds a lock on the table while it is built: on a large PostgreSQL database build it first without the lock ([Running it on a live PostgreSQL database](indexes.md#running-it-on-a-live-postgresql-database)), and the migration then makes none. Writing to the table is a little slower: 100,000 rows inserted in batches took 40% longer, a single notification 0.02 ms more. See [indexes.md](indexes.md#the-notifications-bell). |
| **The scheduler now deletes a notification 30 days after it was read.** | The bell shows the notifications a member has not read and no page lists one they have, so a read notification is a row nobody can see, and nothing deleted one. `PruneReadNotifications` (04:00, in `bootstrap/app.php`) deletes the ones read more than `NOTIFICATIONS_READ_RETENTION_DAYS` (30) days ago. A notification that has not been read is never deleted, and the 30 days count from when it was read. | **The first night deletes every notification that was read more than 30 days ago.** Nothing a member can see is lost, but the rows are gone: set `NOTIFICATIONS_READ_RETENTION_DAYS` (`0` keeps them for ever) before then if you want them. A backlog goes 5,000 rows at a time, 22.6 s for 800,000 rows (measured), and a worker stops a job after 60 seconds ([the queue](redis.md#the-queue)), so several million read rows may take more than one night; what was deleted stays deleted. The job runs on the queue, so the scheduler and a worker must be running. A `config/` that has no `notifications.php` keeps 30 days. |
| **The API reference at `/api/docs` is no longer blank.** | The page loaded Swagger UI from another host, which the content security policy blocked. Swagger UI is now part of the build and comes from the site. Its Try it out leaves the browser's cookies out, so it is answered for the key given under Authorize. | Nothing beyond the usual deploy (`npm ci`, `npm run build`, `php artisan api:docs`). A `resources/views/api/docs.blade.php` of your own keeps its tags, which the policy still blocks. On a site served over plain `http`, Try it out may not be able to write ([api.md](api.md#try-it-out)). |
| **An address with something added to a record's number answers `404`.** | `42abc`, `42-old-title`, `42.`, `+42` or a space beside the number opened record 42 on some databases (most of them were a 500 on PostgreSQL). Only digits are a record's number now, on every database, and a form field that picks a record by its number answers `422` for what is not a whole number. | Nothing, unless links of your own add something to a number: take it off ([Something other than a number](#something-other-than-a-number-where-a-records-number-goes)). |
| **Every answer carries the security headers.** | Unknown addresses, missing records, redirects to sign in, the maintenance page of `php artisan down` and the API had none of them. | Nothing, unless your web server adds the same headers to the application's answers: take them out there ([Security headers on every answer](#security-headers-on-every-answer)). |
| **Stripe's deliveries to `/billing/webhook` fail.** | The webhook that Laravel Cashier adds by itself is switched off: with no signing secret set, it applied unsigned events. | Point the endpoint in Stripe at `/stripe/webhook` ([Stripe events sent to `/billing/webhook`](#stripe-events-sent-to-billingwebhook)). |
| **Only an administrator can touch the administrator role and administrators' accounts. A change on the Discord admin pages needs `system.acp.edit`.** | Permissions meant for one part of the admin area could be used to become an administrator, or to take one's place. | If you ever gave such a permission to somebody else, check who is an administrator, and the Discord mappings ([Staff permissions and the administrator role](#staff-permissions-and-the-administrator-role)). |
| **New colours, type and logo.** | The interface, the logo and every icon in `public/` follow the MetaForger design. | If you changed any of them, read [theming.md](theming.md) before you check out the new code. |

## If you changed the code

A site that is a fork, or that has files of its own, finds out about an upgrade through `git` before it finds out through this page. [Keeping it upgradeable](extending.md#keeping-it-upgradeable) says how to keep your changes where an upgrade does not touch them. In particular:

- **Subclasses and overrides in the shop.** `OrderLifecycle`, `OrderRefunder`, `OrderPlacer` and `OrderPricer` each gained a `GiftCards` constructor argument ([commerce.md](commerce.md#upgrading-an-existing-installation)).
- **The Discord companion** is a separate repository with its own releases. It is listed as `dev-main` in `composer.json`.
- **Language files.** `php artisan lang:check` fails when a language lacks a key that English has ([adding-a-language.md](adding-a-language.md)).
- **Pages written for the earlier front end.** Components now come from Reka UI: a Checkbox or Switch binds with `v-model` or `:model-value` and `@update:model-value` (not `v-model:checked`, `:checked` or `@update:checked`, which Vue accepts and which then do nothing; `npm run lint:check` reports them), icons come from `@lucide/vue`, and Tailwind is configured in `resources/css/app.css` (there is no `tailwind.config.js`).
- **A sign-in provider of your own.** `OAuthUser` has no token properties now (`accessToken`, `refreshToken`, `expiresIn` are gone), and `AbstractOAuth2Provider::mapUserToObject()` takes the profile only, not the token response and the access token. A provider written against the old signature fails when it is loaded; take the arguments out ([social-login.md](social-login.md#what-is-kept-from-a-provider)).
- **A page or a client of your own that saves the profile with a new email address.** `PATCH settings/profile` refuses it without `current_password` (the member's current password) unless the account's address was made up, and `ProfileUpdateRequest` no longer lets the password reach the model. A `User` model of your own needs `routeNotificationForMail()` to send `EmailAddressChanged` to its `oldEmail`, or the notice goes to the new address.
- **Code or tests that turn two-factor off or make recovery codes.** `DELETE /settings/security/mfa` (when two-factor is confirmed) and `POST /settings/security/recovery-codes` are behind `password.confirm`, so they answer with a redirect to `/confirm-password` (a `423` to a request that expects JSON) until the session has a recent `auth.password_confirmed_at`. A test sets it with `withSession(['auth.password_confirmed_at' => time()])`. `POST /settings/security/mfa` refuses to run again while two-factor is on.
- **Code or tests that expect a sign-in button for every switched-on provider.** `OAuthProviders::enabledOptions()` still lists the providers that are switched on (it is what staff pick from when they connect an account by hand), but the sign-in, registration and Security pages use `OAuthProviders::availableOptions()` (switched on **and** set up, see `isConfigured()` and `missingCredentials()`), `SocialLoginController` answers 404 for one that is not `isAvailable()`, and `phpunit.xml` now gives each provider test credentials. A provider class of your own is registered in `ProviderRegistry` with a `credentials` list of the `config/services.php` keys it cannot do without, or it is never offered.
- **Code or tests that use the staff reset.** `DELETE /acp/users/{user}/two-factor` is behind `can:users.acp.reset-two-factor`, needs a `reason` (5 to 500 characters), and is written to the audit log as `users.two-factor.reset`; `TwoFactorReset` is the one place that does it (the admin controller and the console command both call it), and a new notification, `TwoFactorWasReset`, is queued on `mail` (the key group `mail.two_factor_reset`, so a language added by hand needs those keys; `lang:check` says which). The admin user edit page gets a new prop, `twoFactor`; a changed `UsersEdit.vue` should read it.
- **Code or tests that turn two-factor on.** `POST /settings/security/mfa` and `POST /settings/security/mfa/confirm` are behind `RequirePasswordToSetUpTwoFactor` (Laravel's `password.confirm`, skipped for a member whose address ends in `@oauth.local`), so they answer with a redirect to `/confirm-password` (a `423` to a request that expects JSON) until the session has a recent `auth.password_confirmed_at`. A test sets it with `withSession(['auth.password_confirmed_at' => time()])`. The Security page gets a new prop, `twoFactorSetupAsksForPassword`; a changed `Security.vue` should read it.
- **A page, a client or tests of your own that make or rotate a key through the settings routes.** `POST settings/api-keys` and `POST settings/api-keys/{key}/rotate` refuse the request without `current_password` (the member's current password) unless the account's address was made up (`ApiKeys::asksForPassword()`), and both are behind `throttle:current-password` as well as `throttle:api-keys`. The page gets a new prop, `asks_for_password`; a changed `ApiKeys.vue` should read it. A test sends `'current_password' => 'password'` with the factory's member.
- **A changed `Security.vue`, or tests, that read the recovery codes from the Security page.** The `recoveryCodes` prop is empty unless the session carries the one-request flash `TwoFactorAuthenticator::SHOW_RECOVERY_CODES`, which confirming two-factor, a new set and `GET /settings/security/recovery-codes` (behind `password.confirm`) set; `recoveryCodesLeft` is always sent. A page that listed `recoveryCodes` shows nothing until it asks. A test sets the flash with `withSession([TwoFactorAuthenticator::SHOW_RECOVERY_CODES => true])`. The new route is also under `blocked` in `config/impersonation.php`: a site that changed that list needs to add `security.recovery-codes.show`.
- **Stripe.** Cashier 16 sets the Stripe API version for the calls the site makes (`2026-08-26.dahlia` with the packages that `composer.lock` names today). If you use metered billing or coupons, read [Cashier's upgrade guide](https://github.com/laravel/cashier-stripe/blob/16.x/UPGRADE.md).
- **A `config/auth.php` of your own, or code that finds an account by an address someone typed.** `providers.users.driver` is `eloquent-ignoring-case` now (`EmailIgnoringCaseUserProvider`), which is what makes `Auth::attempt()` and the password broker take `Ada@Example.com` for `ada@example.com`; `User::where('email', $typed)` still matches exactly on SQLite and PostgreSQL, so use `EmailLookup::find($typed)`, which is `NamesAreComparedIgnoringCaseTest`'s answer to such a query. A rule that checks whether a name or an address is free is `UniqueIgnoringCase`, not `unique`.
- **Your own tests** should run on the new code before the deploy does: `php artisan test`.

## Checking that it worked

[Checking a deploy](deployment.md#checking-a-deploy) is the list for a deploy. After an upgrade also look at:

| Check | Looks right when |
|---|---|
| `php artisan migrate:status --pending` | Nothing is listed. |
| `storage/logs/laravel.log` | No `critical` line about support attachments. |
| `php artisan support:attachments:privatize --dry-run` | Zero, or only the *missing* ones you know about. |
| `php artisan queue:failed` | Nothing, or only what you know. |
| Sign in as a member, then as an administrator | Both work (everyone was signed out once). |
| **Admin → Access control** | `editor` and `moderator` have what you wrote down, and the new permissions are listed. |
| **Admin → Tokens** | No key still holds only abilities that the site does not have. |
| A picture on a product and on a blog cover | It shows. |
| A test purchase, if you take payments | It is paid, and its email arrives ([payments.md](payments.md)). |

## Going back

The way back is the backup, not `php artisan migrate:rollback`. A migration's `down()` can undo a table, not what the upgrade did to the data around it, and one is empty on purpose: files are never moved back to a public disk.

1. `php artisan down`.
2. Restore the database **and `storage/app`** from the same moment. The files the upgrade moved to the private disk are not where the restored rows say they are if only the database comes back.
3. Check out the earlier commit, `composer install`, `npm ci`, `npm run build`, `php artisan optimize`, `php artisan queue:restart`.
4. `php artisan up`.

People should sign in again after a way back too, because the two builds do not write their sessions the same way.

## What was tried

On 8 November 2026 a database was made with the 75 migrations of the commit of 30 November 2025 ("Hide unauthorized ACP actions", the oldest build tried) on an empty SQLite file. Rows were put into the tables the upgrade changes: two members (member 1 an ordinary member), a blog post and a forum post whose text holds a `<script>` tag and an `onerror` attribute, two API keys (one with the abilities `["read"]`, one with `["*"]`), a support ticket with an attachment row on the public disk, and a Stripe webhook row. Then the code of `main` of that day was run against it:

- **77 migrations ran and none failed**: 69 of the application and 8 of the Discord companion. The tables, columns, indexes and foreign keys that resulted are identical to those of a database made from nothing by the same code (compared as sorted schema dumps).
- The webhook row was given its `provider` and `external_id`. Both keys were kept as they were.
- The attachment was moved to the private disk when its file was on the disk. In a second run with the file missing, the row stayed on the public disk untouched and the command reported it as *missing*.
- `content:sanitize` removed the `<script>` tag, its text and the `onerror` attribute. The blog policy kept the picture and the forum policy dropped it. The paragraph stayed in both.
- The role seeder gave the `admin` role to member 1, an ordinary member. Run a second time it changed nothing (87 permissions, 4 roles).

What has **not** been tried, and so is not promised:

- **An upgrade of a MySQL or PostgreSQL database that holds data.** Their migrations run from empty in CI, and the whole test suite runs on each, but the rehearsal above is SQLite.
- **A build older than 30 November 2025.**
- **A site with changes of its own.**
- **Sessions across the upgrade**: the sign-out is what the code says, not something watched.
- **A real Stripe or Tebex account.** [payments.md](payments.md) says what has been tried with each.
- **Meilisearch across an upgrade.**
