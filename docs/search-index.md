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

The words stored are plain text, and a document stops at 8,000 characters.

## Searching

With `SEARCH_DRIVER=meilisearch`, `/search` (header JSON) and `/search/results` (Inertia page) read the blogs, forum threads and FAQs indexes through Scout. The response shape matches the database path (grouped items, per-group pagination, type filters, website-section gating, `<mark>` highlights). Forum reply text can match on Meilisearch because it is stored on the thread document; the database path still only matches title, excerpt and author.

With `SEARCH_DRIVER=database`, those endpoints keep using `LIKE` as before.

If Meilisearch cannot be reached while the driver is set to `meilisearch` (connection failure, or HTTP 404 / 408 / 502 / 503 / 504), `GlobalSearchService` logs one warning, opens a short circuit (`SEARCH_MEILISEARCH_CIRCUIT_SECONDS`, default 60), and serves the database path for that whole request. While the circuit is open, later searches skip Meilisearch entirely so the warning is not repeated every hit. Auth and misconfiguration errors (HTTP 401 / 403) and other unexpected failures still fail loudly. Artisan sync and reindex stay fail-loud; they do not fall back.

## Index settings

Settings live in code (`App\Support\Search\SearchIndexSettings`) and in `config/scout.php`, keyed by model class so the index name comes from each model's `searchableAs()` (including `SCOUT_PREFIX` when set). Each index lists its searchable attributes in ranking order, turns typo tolerance on, and leaves filterable and sortable attributes empty (visibility is decided when a document is written, not at query time).

With Meilisearch running and `SEARCH_DRIVER=meilisearch`, apply them:

```bash
php artisan metaforge:search:sync
```

That command only pushes settings. It does not import documents. With `SEARCH_DRIVER=database` it refuses and says so.

## Rebuilding documents

To flush each index and import every record that may be listed:

```bash
php artisan metaforge:search:reindex
```

Settings are applied first (same as sync). Pass `--skip-sync` to import only, or `--chunk=100` to change the batch size. Import runs in-process; a queued rebuild and a zero-downtime index swap are not built yet. With `SEARCH_DRIVER=database` the command refuses and says so.

A bulk import only loads rows that already qualify (published blogs and due scheduled posts, published threads, published FAQs, active products).

## Checking on it

**Admin → System settings** has a **Search** card, and `php artisan metaforge:search:health` says the same on the command line. They ask Meilisearch (waiting no longer than `SEARCH_HEALTH_TIMEOUT` seconds, default 3, for any answer) and report:

- whether it is **reachable**, which **version** it is, and when the settings were last pushed and the documents last rebuilt (noted by `metaforge:search:sync` and `metaforge:search:reindex`);
- for each index, **how many documents it holds against how many the database says it should** (counted the way a reindex would), and whether its **settings are the coded ones**. Anything else you changed on the server, such as stop words or synonyms, is left alone;
- how many **updates Meilisearch refused** since the last rebuild, and why the first was;
- whether search is **on the database for a moment** after a failure (the circuit in [Searching](#searching)).

Each problem comes with the command that puts it right:

| It says | Run |
|---|---|
| No index exists yet, or a named one does not | `php artisan metaforge:search:reindex` |
| A count is off (a post saved while Meilisearch was down, or imported by a script) | `php artisan metaforge:search:reindex` |
| Settings are not the coded ones | `php artisan metaforge:search:sync` |
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
    "name": "MetaForge",
    "description": "Search, index and settings for the app",
    "actions": ["search", "documents.add", "documents.delete", "indexes.create", "indexes.get", "indexes.delete", "settings.get", "settings.update", "tasks.get", "stats.get", "version"],
    "indexes": ["*"],
    "expiresAt": null
  }'
```

Put the `key` it answers with in `MEILISEARCH_KEY`. If the site uses `SCOUT_PREFIX`, `indexes` can be `["<prefix>*"]`. Two of the actions only feed the health check: without `version` the card shows the version as unknown, and without `tasks.get` it cannot count refused updates. Meilisearch's *Default Admin API Key* also works, but it can do everything, including manage keys.

## When Meilisearch is down

**Searching** keeps working: the page falls back to the database (see [Searching](#searching)).

**Saving** does not, by default. Scout indexes a record while the request that saved it is handled (`SCOUT_QUEUE=false`), so with Meilisearch down the post, FAQ, product or forum reply **is stored and then the request fails** with a connection error. That reaches a member posting a reply as much as an editor publishing a post. Set **`SCOUT_QUEUE=true`** (and keep a queue worker running, as for email) to index on the queue instead: the save works and the job waits for Meilisearch, and if it gives up it ends in the failed jobs.

Either way, anything saved while Meilisearch was down is missing from the index afterwards. The health card shows the count that is off, and `php artisan metaforge:search:reindex` puts it right. The card also says so while indexing is not on the queue.

## Deploying

- **Self-hosted:** `docker compose --profile search up -d meilisearch` runs the version this app is tested against (`getmeili/meilisearch:v1.12`, in `compose.yaml`). Set a master key of at least 16 bytes (`MEILI_MASTER_KEY`: Meilisearch will not start in production without one), make the key above, and keep the data volume. The index is a copy of what is in the database, so the simplest upgrade or recovery is to start with an empty Meilisearch and run `metaforge:search:sync` and then `metaforge:search:reindex`.
- **Meilisearch Cloud:** use the project's URL as `MEILISEARCH_HOST` and a key made as above (its *Default Admin API Key* works, with the caveat in [Keys](#keys)). Run `metaforge:search:sync` and `metaforge:search:reindex` once.
- Run `metaforge:search:health` in a deploy script, and `metaforge:search:reindex` again if it says a count is off.

## Not built

- A scheduled blog post is not added to the Meilisearch index by the clock: it is added when the post is saved after it is due, or when the index is rebuilt. The database search path still finds a due scheduled post without that save.
- Queued rebuild and zero-downtime index swap.
- Indexing on the queue is **not the default** (`SCOUT_QUEUE=false`), so a site that wants saves to survive a Meilisearch outage has to set it.
- Telling anybody when the health check finds a problem (it shows on the System page and in the command; nothing is sent).
- Products on the global search page.

## Testing

`tests/Feature/Search/SearchIndexTest.php` uses Scout's collection engine: a draft, an unpublished thread, an unpublished FAQ, an archived product and a held reply are left out, unpublishing a post removes its document, and taking down a member's posts removes their threads and reply text from the index.

`tests/Feature/Search/SyncSearchIndexSettingsCommandTest.php` checks that sync refuses the database driver and pushes settings for every index when Meilisearch is configured (through a fake engine, without a live server).

`tests/Feature/Search/ReindexSearchCommandTest.php` checks that reindex refuses the database driver, flushes each index, imports only searchable rows, and can skip settings with `--skip-sync`.

`tests/Feature/Search/GlobalSearchMeilisearchTest.php` runs the search page and header JSON with `SEARCH_DRIVER=meilisearch` backed by Scout's collection engine: cross-group results, unpublished exclusion, type filters, pagination, reply-text highlights and disabled website sections.

`tests/Feature/Search/SearchHealthTest.php` runs the health check through the real Meilisearch client and a stand-in that answers by path (`tests/Support/FakeMeilisearch.php`): a healthy engine, one that cannot be reached or stops answering, a missing key, a key that is refused, fresh installs and missing indexes, each way the settings can differ (and what Meilisearch adds that is not a difference), document counts that are off (singular and plural), an index still updating, refused updates and the time they are counted from, an open circuit, the record of the last sync and rebuild, and that no message ever carries the key. `SearchHealthCommandTest.php` covers the command (output, `--json`, exit codes), `SearchIndexStateTest.php` and `SearchIndexStateThatCannotBeKeptTest.php` the record of the last sync and rebuild (written only by a command that did its work, and never failing one), and `tests/Feature/Admin/SystemSearchHealthTest.php` the card on the System page (deferred, never holding the page up, no more permission than the page needs).

`tests/Live/Meilisearch/MeilisearchLiveTest.php` runs against a **real Meilisearch** and is what the CI `search (Meilisearch 1.12)` job runs: the settings Meilisearch ends up with, only what a guest can open being listed, a title ranking above a body, which typos are forgiven (and that a word under four letters forgives none), what leaves the index on unpublish and delete, a held reply never reaching a thread's document, the search page reading the index (with a typo only Meilisearch would forgive) and falling back to the database when it is unreachable, a save during an outage failing in the request and succeeding on the queue, the health check noticing a missing document, changed settings, no index, a wrong key and no key (and putting each right with the command), and a key with only the actions listed in [Keys](#keys) doing everything the app does, while one without `settings.update` is refused. These are in `tests/Live`, which `php artisan test` does not run; they skip themselves unless `MEILISEARCH_LIVE_HOST` and `MEILISEARCH_LIVE_KEY` are set, and each run uses its own index prefix and removes its indexes. To run them locally:

```bash
docker compose --profile search up -d meilisearch
MEILISEARCH_LIVE_HOST=http://127.0.0.1:7700 MEILISEARCH_LIVE_KEY=<the master key> php artisan test tests/Live
```

`tests/Unit/Search/MeilisearchCircuitBreakerTest.php` and `tests/Feature/Search/GlobalSearchMeilisearchFallbackTest.php` cover the open circuit, which failures trigger fallback, and a throwing Scout engine that still returns database-shaped results with a single warning.
