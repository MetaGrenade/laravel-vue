# Indexes

Every foreign key starts an index, on every database the app supports, and a test fails when a new one does not. Before this, MySQL and MariaDB had them and PostgreSQL and SQLite did not. Four lists have an index for the order they are shown in, too ([the order of the lists](#the-order-of-the-lists)), and the log of requests made with an API key has two for the questions asked of it ([the API request log](#the-api-request-log)).

> **Status:** in place. Three migrations: `2026_11_10_000000_index_foreign_keys.php` added the missing foreign key indexes, `2026_11_11_000000_index_the_lists.php` added the four for the lists, and `2026_11_09_000002_index_the_api_request_log.php` added the two for the log (dated before the first on purpose, [see below](#how-the-two-indexes-were-shaped)). `ForeignKeyIndexTest`, `ListOrderIndexesTest` and `ApiRequestLogIndexesTest` (in `tests/Feature/Database`) run with the rest of the suite on SQLite, MySQL and PostgreSQL. Not built: indexes for the leaderboard, the top threads by views and the other lists left out on purpose, and a check that no index goes unused. They are listed at the end.

## Why

A foreign key is a promise (this post belongs to a thread that exists), and a promise is not an index. MySQL and MariaDB (InnoDB) make an index for the column when the constraint is made, because the check needs one. PostgreSQL and SQLite do not. The migrations only said `->constrained()`, so on those two:

- the page of a thread read every post on the site to find the posts of that thread, because nothing could find them by thread;
- the same for a post's comments, a member's tickets, an order's lines, a conversation's messages, and so on down the list;
- **deleting a member checked every table that points at them with a full scan** (`ON DELETE CASCADE` and `SET NULL` look the child rows up the same way), about sixty foreign keys, each on a table as big as the community.

When the check was made, 124 of the 174 foreign keys on SQLite and 125 of the 175 on PostgreSQL had no index. Nobody saw it because the tests and a small site are fast anyway, and MySQL, the database most people try first, hid it.

## What it measured

PostgreSQL 16 in a container, a throwaway database filled with a large forum: 20,000 members, 100,000 threads, 1,000,000 posts, 200,000 blog comments, 50,000 tickets. The same queries the pages run, `EXPLAIN ANALYZE`, before and after the migration:

| Page | Query | Before | After |
|------|-------|--------|-------|
| A thread | its first 10 posts | 24.2 ms | 0.15 ms |
| A thread | how many posts it has | 23.6 ms | 0.07 ms |
| A member's profile | their latest 5 posts | 23.6 ms | 0.29 ms |
| A blog post | its first 10 comments | 7.7 ms | 0.15 ms |
| The support centre | a member's tickets | 2.0 ms | 0.05 ms |
| A board | its first 20 threads (10,000 in the board) | 8.9 ms | 4.8 ms |
| Deleting one member | with 5 threads and 100 posts | **1009 ms** | **8.5 ms** |

Every scan above reads the whole table, so the "before" column grows with the site: twice the posts, twice the time, on every thread page, several times a page. The board is the one that barely moved: it has an index to find the board's threads now, but still sorts all 10,000 of them to pick 20 (see the end).

Deleting a member was the worst: 741 ms of the second was the check that no thread names one of their posts as its accepted answer (`forum_threads.solved_post_id`, once for each of the 100 posts), and 191 ms the check on the posts of their threads.

Making the 125 indexes took 2 s on that database.

## What changed

`2026_11_10_000000_index_foreign_keys.php` looks at every foreign key in the database. For each one that no index starts with, it makes an index on the column (or columns). Where something already starts with the column, including the primary key, a unique key and an index a person made, it does nothing.

- **On MySQL and MariaDB it does nothing at all**, because every foreign key is covered already. It is safe to run again anywhere.
- **It writes down what it makes.** Each index it creates is recorded in a table, `foreign_key_indexes` (`table_name`, `index_name`), and `down()` drops exactly the indexes in that table and then the table. It never goes by a name or a suffix: an index somebody made before, a unique key included, is theirs even when it is called `something_fk` or has the very name this migration would have given (it is then left alone, and the column counts as covered). An index someone removed by hand, or a table that is gone, is skipped. On MySQL and MariaDB it makes no table either.
- The names end in `_fk` (`forum_posts_forum_thread_id_fk`) so a reader can tell what an index is for; nothing relies on it. A name that would be longer than PostgreSQL keeps (63 characters; MySQL allows 64) keeps its first 51 characters, then a short hash of the whole name, then `_fk`, and is the same each time it is made. None of today's names needs that: the longest, `support_ticket_message_attachments_support_ticket_message_id_fk`, is exactly 63.
- It is the migration that does this, and not an edit of the old ones, because a migration that has run never runs again. A database that already exists gets the indexes the next time it migrates.

## The rule for new tables

Give the column an index where the table is made:

```php
$table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
```

`index()` comes before `constrained()`, which returns the foreign key and not the column. MySQL uses that index for the constraint, so it does not make a second one. A table whose foreign key is the first column of a composite index or a unique key needs nothing more:

```php
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->unique(['user_id', 'badge_id']);   // starts with user_id: covered
```

The second column of that unique key (`badge_id`) is not covered by it, and a page that asks "who has this badge" needs its own index.

## The test

`ForeignKeyIndexTest::every_foreign_key_starts_an_index` asks the schema for every foreign key and fails with the list of the ones nothing starts. It runs on whatever database the suite runs on, so a migration without the `index()` fails in the default SQLite run and not only in `ci-pgsql`. The message says what to write.

The other tests in that file hold the migration to what it promises: it makes the index, writes it down, and `down()` takes back exactly what was written down and the list with it; a person's own indexes survive `down()` (a unique key ending in `_fk`, an index with the exact name the migration would give, a composite that covers the column) and are not doubled; an index taken away by hand is made again and listed once, and `down()` copes with one that is gone or whose table is; two foreign keys on one column get one index; no index name is longer than 63 characters and a name that would be is cut the same way each time (tried on its own, since none needs it); on MySQL it makes nothing, not even the list.

## The order of the lists

A foreign key index finds the rows of a parent. It does not put them in the order a page shows them, so a page that asks for "the newest 24" or "the pinned ones first" still read every row that matched to sort them. The same stack was replayed on the pages, with the plans looked at one query at a time, and four were worth an index. `2026_11_11_000000_index_the_lists.php` makes them:

| Index | Serves |
|-------|--------|
| `forum_posts (created_at)` | the five latest posts on the forum index, across every thread |
| `blog_comments (status, blog_id)` | the approved comments of a post (the list, the count) and the count behind "most commented" for every post |
| `forum_threads (is_published, forum_board_id, is_pinned, last_posted_at, created_at)` | a board's threads, pinned first, then the latest reply |
| `users (is_banned, created_at)` | the member list, the newest first, without the banned |

### What it measured

PostgreSQL 16 in a container, the database from above grown to 200,000 members and 5,000 blog posts with 200,000 comments. `EXPLAIN ANALYZE` of the query each page runs, on a vacuumed table:

| Page | Query | Before | After |
|------|-------|--------|-------|
| Forum index (when its cache is empty) | the 5 latest posts, 1,000,000 posts | 142 ms | 1 ms |
| A board | its first 15 threads, 10,000 in the board | 7.2 ms | 0.2 ms |
| The member list | the newest 24, 200,000 members | 21 ms | 0.03 ms |
| A blog post | the 3 most commented, 5,000 posts | 186 ms | 23 ms |

One run of each after a warm-up run, on a laptop; earlier runs on the same data landed within about a third of these (116 ms for the posts, 4.2 ms for the board), so read the size of the step and not the digits.

MariaDB 11.4 with 300,000 posts, 50,000 threads, 100,000 members, 2,000 posts and 100,000 comments agrees: 250 ms to 0.4 ms, 4 to 5 ms to 0.5 ms, 18 ms to 0.1 ms, 73 to 78 ms to 17 ms. The migration took half a second on the PostgreSQL database.

The forum index keeps its numbers for a few minutes, so its latest-posts query runs once for each time that cache is empty, not for each visitor; the others run for each page view. The most commented query is the one that grows with the number of posts: it counts the comments of every post before it picks three. The index makes each count an index-only lookup (23 ms for 5,000 posts), and it still reads every post. A site with many thousands of posts would want the count kept on the post, which is a different change.

### What it costs

Writes pay for an index. Measured on PostgreSQL in one transaction (best of two, rolled back, a vacuum between runs):

| Write | Without | With |
|-------|---------|------|
| 200,000 posts | 2.31 s | 2.55 s |
| 100,000 comments | 0.96 s | 1.16 s |
| 50,000 replies (`last_posted_at` changes) | 0.63 s | 0.74 s |
| 50,000 members | 0.33 s | 0.37 s |

That is 10 to 20 percent more time to write, one to two microseconds a row. The indexes are 21 MB on the 96 MB posts table, 6 MB on the 22 MB threads table, 6 MB for 200,000 members and 1.5 MB for the comments.

### How they were shaped

- **Equal columns first, the sort last.** `is_published` and `forum_board_id` are compared with `=`, so the index can start there and then hand the rows over already in the order of `is_pinned`, `last_posted_at`.
- **Every column the page sorts by, the tie-break too.** The board's page also sorts by `created_at`. PostgreSQL copes with a sort key the index lacks (an incremental sort), MySQL and MariaDB do not: without `created_at` in the index MariaDB chose the board's own foreign key index and sorted all 5,000 threads (4 to 6 ms). With it, 0.4 ms.
- **None starts with a foreign key column.** MySQL gives a foreign key its own index; one that starts with the same column takes over from it, and then neither can be dropped (error 1553 on rollback). `ListOrderIndexesTest::none_of_them_starts_with_a_foreign_key_column` fails when one does.
- **It writes down what it makes**, as the foreign key migration does, in a `list_order_indexes` table, and `down()` drops exactly those. An index somebody made before (to build it without a lock, say) is theirs: when it starts with the same columns, under any name, this migration makes none and `down()` leaves it. One that has the same columns in another order does not cover the list, and gets its own beside it. The table is made on every database, MySQL included, because here an index can be missing on all of them.

### Left alone on purpose

- **The leaderboard** (`reputation_points`): 20 ms at 200,000 members. Reputation changes on every upvote, and an index on it would turn each of those updates into a rewrite of a wide row. It wants a cache for a minute, not an index.
- **The forum index's top threads** (`views`): the same, a view is an update. 24 to 33 ms, cached.
- **The latest thread of each board**, **the blog list** (1 ms at 5,000 posts), **the forum feed** (12 to 18 ms, kept five minutes now) and **the sitemap** (built once an hour).
- **Counts.** The member list's `count(*)` is still 21 ms at 200,000 members, indexed or not: a count reads every row it counts.

## The API request log

Every request made with an API key is a row in `token_logs`: what was asked, from which address, what came back, how long it took ([the request log](api-keys.md#the-request-log)). Nothing ever deleted a row, and the only index was the foreign key's, so three readers got slower with every request the site had ever served:

- **A key with an hourly or a daily limit** counts its rows of the last hour and of the last day before every request it makes (`ThrottleTokenUsage`). That read the key's whole history, and once one key had 40% of a million rows MariaDB gave up the index and read the table.
- **Admin → Tokens** counts the last hour and the last day for every key, lists the newest rows (`ORDER BY created_at DESC`) and counts all of them for the pager.
- **The nightly prune** (`PruneTokenLogs`, new) deletes what is older than the retention, 5,000 rows at a time, and each of those statements read the table to find them.

`2026_11_09_000002_index_the_api_request_log.php` makes two indexes:

| Index | Serves |
|-------|--------|
| `token_logs (personal_access_token_id, created_at)` | a key's rows since a time (the count behind a limit) and the oldest of them (when the limit lets the key through again) |
| `token_logs (created_at, personal_access_token_id)` | the newest rows, a stretch of time and the count per key in it (the admin page, the prune); it holds the key of each row, so counting per key never reads the table |

### What the two indexes measured

MariaDB 11.4 in a database made for the run: 1,000,000 rows over 90 days with the recent days the busiest (the last hour holds 77,000 of them, so a hot table), 20 keys, one with 40% of the rows. The median of five runs of the query that the middleware or the page makes:

| Query | Before | After |
|-------|--------|-------|
| A limit's count, the busiest key, the last hour | 129 ms | 5.3 ms |
| A limit's count, the busiest key, the last day | 138 ms | 13 ms |
| A limit's count, a key with a thirtieth of the rows, the last day | 34 ms | 3.3 ms |
| When the busiest key may call again (its oldest row in the day) | 380 ms | 0.3 ms |
| Admin → Tokens: calls of every key in the last hour | 155 ms | 16 ms |
| Admin → Tokens: calls of every key in the last day | 172 ms | 43 ms |
| Admin → Tokens: the newest 10 rows | 129 ms | 0.2 ms |
| Admin → Tokens: the newest 10 failed rows | 140 ms | 0.6 ms |
| The nightly prune, 306,000 rows due (a 30 day retention) | 11.6 s | 2.7 s |

A key that has both limits pays both counts on every request, so on this table a request used to cost about 270 ms more and costs about 18. The quota check was the one that mattered: it ran for every request of such a key, because the cached count is forgotten after each response (`ThrottleTokenUsage::handle`), and it read more with every request the key had ever made.

### What the two indexes cost

- **Writes.** Inserting 300,000 rows in batches took 4.79 s without the indexes and 5.65 s with them (the median of three alternating runs: 18% more). One row at a time, which is what a request does, took 1.03 ms and 1.02 ms: the round trip hides the difference.
- **Space.** The indexes are 76 MB beside 156 MB of rows at a million rows; they were 24 MB. The retention keeps the table from growing past its window.
- **One query is slower.** The pager's `count(*)` of the whole list took 70 ms and takes 92 ms, because the smallest index it can count with is bigger now. The retention bounds it.
- **The migration.** 1.6 s to make both on the million rows (MariaDB); `down()` took 6.1 s there, because it takes the foreign key off and puts it back ([below](#how-the-two-indexes-were-shaped)).

### How the two indexes were shaped

- **The key comes first for a limit, and cannot for the rest.** A key's count needs `(key, time)`; a list by time, a count per key and the prune need the time first. An index with the time first alone was tried for both: the busiest key's count was 9.3 ms and 25 ms (better than before, worse than 5.3 and 13), and a quiet key's was 36.7 ms, no better than the 34 it started at, because MariaDB chose the key's own index and read its history.
- **The first one starts with the foreign key's column**, unlike the four for the lists. MySQL and MariaDB give a foreign key an index of its own and drop it when another that starts with the same column is made, after which neither the key nor the new index can be dropped (error 1553). So `down()` takes the constraint off, drops the index and puts the constraint back, which gives it its own index again (`ApiRequestLogIndexesTest` runs it, and the same migration with that step taken out fails with 1553 on MariaDB). On PostgreSQL and SQLite `down()` gives the column the `..._fk` index `index_foreign_keys` would have given it, and writes it in that migration's list, so the rule that every foreign key starts an index holds after a rollback of this one alone.
- **Its date is before the foreign key migration's on purpose.** That migration makes an index for every column that starts none. Dated after it, this one left the column with two (that migration's `..._fk` and this); `ForeignKeyIndexTest` showed it, because after the `down()` and `up()` of that migration the `..._fk` index was not made again, the column being covered. Dated before it, the column already starts an index when it runs, and it makes nothing for `token_logs`. A database that has already run it keeps the one it made, which is harmless.
- **An index somebody made before is theirs.** When one starts with the same columns, under any name, this migration makes none, and `down()` drops only the two names it gave (`token_logs_by_key_and_time`, `token_logs_by_time_and_key`, not names Laravel would pick).

### Left alone in the request log

- **The count behind a limit is still made for every request of a key that has a limit.** The cached count is forgotten after each response so that the next request sees the row the last one wrote; keeping a counter instead changes what is counted when the cache is lost, and with the index the count reads only the key's own last hour or day, which holds about as many rows as the key's limit allows (a request that is refused for being over its limit is not logged).
- **What a row holds.** The whole JSON answer is kept: 88 bytes for the profile, more for a long list or a ticket with many messages. Not measured on a long ticket, and not changed here ([Not built](api-keys.md#not-built)).

## The address typed in other letters

`users_lower_email_index` is an index on `LOWER(email)`, made by `2026_11_19_000000_index_the_lowercase_email.php` on **SQLite and PostgreSQL only**.

An address typed in other letters than the account holds (`Ada@Example.com` for `ada@example.com`) is found by asking `LOWER(email) = LOWER(?)` (`App\Support\Members\EmailLookup`, behind the sign-in page, the API sign-in, the password reset and a provider's verified address). MySQL and MariaDB compare text without regard to case, so there the unique index on `email` already answers it. SQLite and PostgreSQL compare exactly: the unique index cannot answer a `LOWER()` of the column, so without this one the question reads every member, and it is asked for every address typed that does not exist, which is every wrong address and every bot. The address as typed is asked first (the unique index), so a person who types it right never reaches the second question.

MySQL and MariaDB get no index, and are not asked the second question. The `LOWER(nickname)` and `LOWER(email)` that the rule `UniqueIgnoringCase` asks when a name or an address is chosen or changed are not indexed on purpose: they run on registration and on a profile change, never on a page or a search.

`tests/Feature/Database/LowercaseEmailIndexTest.php` checks that the index is there on the two databases that need it and not on the others, that the migration rolls back and forward (twice over, so an index built by hand first, `CREATE INDEX CONCURRENTLY users_lower_email_index ON users (LOWER(email))`, is left alone), and, on SQLite, that the query the lookup runs is answered by the index and not by reading the table.

## Dropping a column on SQLite

SQLite refuses to drop a column that an index uses (`error in index ... after drop column`), so a migration that removes a foreign key column drops the index on it first, and so does one that removes a column the list indexes use (`created_at`, `status`, `is_pinned` and the rest). The migration tests that roll an older migration back by hand do what `migrate:rollback` does: the newer migration comes off first. They call `Tests\Support\ForeignKeyIndexes::takeOff()` before and `putBack()` after (`CouponMigrationsTest` is the pattern). PostgreSQL drops the indexes of a dropped column by itself.

## Running it on a live PostgreSQL database

`CREATE INDEX` holds a lock that stops writes to the table while it is built. On a small site that is not noticeable (the 1,000,000-post table above took a moment); on a large one, run the migration when the site is quiet, or build the biggest indexes by hand first without the lock:

```sql
CREATE INDEX CONCURRENTLY forum_posts_forum_thread_id_fk ON forum_posts (forum_thread_id);
```

The migration skips a column that something already starts, so it does not repeat those. It did not make them, so `down()` leaves them where they are, whatever they are called. The list indexes take half a second on the same database, and `forum_posts (created_at)` is the largest; build that one first the same way if the table is big:

```sql
CREATE INDEX CONCURRENTLY forum_posts_created_at_index ON forum_posts (created_at);
```

## What is not covered

- **The order of the other lists.** Four are done ([above](#the-order-of-the-lists)). The leaderboard, the top threads by views, the latest thread of each board, the blog list, the forum feed and the sitemap were measured and left, with the reason. A new list that sorts a table that grows wants its own index, shaped like its query and measured on its own: an index slows every write to the table.
- **Counts.** `count(*)` on a table that grows (members, threads) is a scan on every database. The forum index already keeps its counts for a few minutes; the member list does not (21 ms at 200,000 members).
- **"Most commented" at many thousands of posts.** It counts every post's comments to pick three. A count kept on the post is the fix, and a change of its own.
- **Indexes nobody uses.** Every foreign key got one because the rule is simple, and a few belong to columns no page asks for (`licence_keys.added_by`). They cost a little on every write to that table, and they are what makes deleting the parent row fast, so they stay until a measurement says otherwise.

## Finding the next one

The plans of every query the 36 budgeted pages run can be listed from the test suite (`tests/Feature/Performance`, see [query-budgets.md](query-budgets.md)): replay each page, run `EXPLAIN` on each `select`, and look for the ones that scan or sort. On PostgreSQL, `SET enable_seqscan = off` first: a query that still shows a sequential scan has no index it could use, whatever the size of the table in your test database. That is how the lists above were found. For a sort, look for a `Sort` node above a scan, and try the index in a throwaway database before writing the migration; on MySQL or MariaDB look for `Using filesort` in `EXPLAIN`.
