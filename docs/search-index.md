# Search index

What may be put in the search index, how its Meilisearch settings are applied, how documents are rebuilt, and how the search page uses that index when Meilisearch is configured.

> **Status:** index rules, settings sync, reindex and the Meilisearch query path for `/search` and `/search/results` are in place. A zero-downtime index swap, a fallback when Meilisearch is down, ACP health, and a CI Meilisearch job are not built yet.

## What is listed

`SEARCH_DRIVER=database` (the default) does not talk to Meilisearch. Scout stays on its null engine, so saving a record does not try to reach one, and the search page uses case-insensitive `LIKE` queries. `SEARCH_DRIVER=meilisearch` is the only value that indexes and searches Meilisearch. `MEILISEARCH_HOST` and `MEILISEARCH_KEY` are server-side. The key is a search key, not the master key, and it is never sent to the browser.

Four indexes, and a record is in one only when a guest could already open it:

- **Blogs** (`blogs`). A published post, or a scheduled post whose time has arrived. A draft, an archived post, and a scheduled post that is still in the future stay out.
- **Forum threads** (`forum_threads`). A published thread, including the text of its visible replies. A thread that is not published stays out, and so does a reply that is held or deleted. Boards are public, so there is no permission to apply. A board that later requires one is excluded in `ForumThread::shouldBeSearchable()`.
- **FAQs** (`faqs`). A published question, with its category's name.
- **Products** (`products`). A product that is for sale. An archived product stays out. The shop uses the same rule. Products are not shown on the global search page yet.

Saving a record that stops qualifying removes its document. A new or edited reply asks for the thread's document again, once an engine is in use. Taking down a member's posts in bulk (see [moderation.md](moderation.md)) skips those model events, so the clean-up removes the unpublished threads from the index and rebuilds every other thread that lost a reply.

The words stored are plain text, and a document stops at 8,000 characters.

## Searching

With `SEARCH_DRIVER=meilisearch`, `/search` (header JSON) and `/search/results` (Inertia page) read the blogs, forum threads and FAQs indexes through Scout. The response shape matches the database path (grouped items, per-group pagination, type filters, website-section gating, `<mark>` highlights). Forum reply text can match on Meilisearch because it is stored on the thread document; the database path still only matches title, excerpt and author.

With `SEARCH_DRIVER=database`, those endpoints keep using `LIKE` as before. If Meilisearch cannot be reached while the driver is set to `meilisearch`, the request fails (there is no silent fallback yet).

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

## Not built

- A scheduled blog post is not added to the Meilisearch index by the clock: it is added when the post is saved after it is due, or when the index is rebuilt. The database search path still finds a due scheduled post without that save.
- Queued rebuild and zero-downtime index swap.
- A fallback to the database when Meilisearch cannot be reached, the ACP health check, and a CI job that runs Meilisearch.
- Products on the global search page.

## Testing

`tests/Feature/Search/SearchIndexTest.php` uses Scout's collection engine: a draft, an unpublished thread, an unpublished FAQ, an archived product and a held reply are left out, unpublishing a post removes its document, and taking down a member's posts removes their threads and reply text from the index.

`tests/Feature/Search/SyncSearchIndexSettingsCommandTest.php` checks that sync refuses the database driver and pushes settings for every index when Meilisearch is configured (through a fake engine, without a live server).

`tests/Feature/Search/ReindexSearchCommandTest.php` checks that reindex refuses the database driver, flushes each index, imports only searchable rows, and can skip settings with `--skip-sync`.

`tests/Feature/Search/GlobalSearchMeilisearchTest.php` runs the search page and header JSON with `SEARCH_DRIVER=meilisearch` backed by Scout's collection engine: cross-group results, unpublished exclusion, type filters, pagination, reply-text highlights and disabled website sections.
