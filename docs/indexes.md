# Indexes on foreign keys

Every foreign key starts an index, on every database the app supports, and a test fails when a new one does not. Before this, MySQL and MariaDB had them and PostgreSQL and SQLite did not.

> **Status:** in place. One migration (`2026_11_10_000000_index_foreign_keys.php`) added the missing ones, and `tests/Feature/Database/ForeignKeyIndexTest.php` runs with the rest of the suite on SQLite, MySQL and PostgreSQL. Not built: indexes for the sort order of the lists (the member list, the leaderboard, a board's threads), and a check that no index goes unused. Both are listed at the end.

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
- The names end in `_fk` (`forum_posts_forum_thread_id_fk`), so `down()` takes back exactly these and never an index somebody made. A name that would be longer than PostgreSQL keeps (63 characters; MySQL allows 64) keeps its first 51 characters, then a short hash of the whole name, then `_fk`, and is the same each time it is made. None of today's names needs that: the longest, `support_ticket_message_attachments_support_ticket_message_id_fk`, is exactly 63.
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

The other tests in that file hold the migration to what it promises: it makes the index and `down()` takes back only that; it leaves a person's own index alone and does not double it; no index name is longer than 63 characters and a name that would be is cut the same way each time (tried on its own, since none needs it); on MySQL it makes nothing.

## Dropping a column on SQLite

SQLite refuses to drop a column that an index uses (`error in index ... after drop column`), so a migration that removes a foreign key column drops the index on it first. The migration tests that roll an older migration back by hand do what `migrate:rollback` does: the newer migration comes off first. They call `Tests\Support\ForeignKeyIndexes::takeOff()` before and `putBack()` after (`CouponMigrationsTest` is the pattern). PostgreSQL drops the indexes of a dropped column by itself.

## Running it on a live PostgreSQL database

`CREATE INDEX` holds a lock that stops writes to the table while it is built. On a small site that is not noticeable (the 1,000,000-post table above took a moment); on a large one, run the migration when the site is quiet, or build the biggest indexes by hand first without the lock:

```sql
CREATE INDEX CONCURRENTLY forum_posts_forum_thread_id_fk ON forum_posts (forum_thread_id);
```

The migration skips a column that something already starts, so it does not repeat those. Use the name the migration would, so a later `down()` takes it back.

## What is not covered

- **The sort order of the lists.** An index to find a board's threads does not also order them. A board of 10,000 threads reads all of them to show 20 (the 4.8 ms above), and the same is true of the member list and the leaderboard, which sort every member, and the blog list, the forum feed and the sitemap, which sort every published row. Each wants a composite index shaped like its query (`forum_threads (forum_board_id, is_published, is_pinned, last_posted_at)`), and each should be measured on its own before it is added: an index slows every write to the table.
- **Counts.** `count(*)` on a table that grows (members, threads) is a scan on every database. The forum index already keeps its counts for a few minutes; the member list does not.
- **Indexes nobody uses.** Every foreign key got one because the rule is simple, and a few belong to columns no page asks for (`licence_keys.added_by`). They cost a little on every write to that table, and they are what makes deleting the parent row fast, so they stay until a measurement says otherwise.

## Finding the next one

The plans of every query the 36 budgeted pages run can be listed from the test suite (`tests/Feature/Performance`, see [query-budgets.md](query-budgets.md)): replay each page, run `EXPLAIN` on each `select`, and look for the ones that scan or sort. On PostgreSQL, `SET enable_seqscan = off` first: a query that still shows a sequential scan has no index it could use, whatever the size of the table in your test database. That is how the list above was found, and how the sort-order work should start.
