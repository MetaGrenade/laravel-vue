# Search index

What may be put in the search index. The search page still reads the database.

> **Status:** the index rules are in place. Searching that index, a rebuild, and a fallback when Meilisearch is down are not built yet.

## What is listed

`SEARCH_DRIVER=database` (the default) does not talk to Meilisearch. Scout stays on its null engine, so saving a record does not try to reach one. `SEARCH_DRIVER=meilisearch` is the only value that does. `MEILISEARCH_HOST` and `MEILISEARCH_KEY` are server-side. The key is a search key, not the master key, and it is never sent to the browser.

Four indexes, and a record is in one only when a guest could already open it:

- **Blogs** (`blogs`). A published post, or a scheduled post whose time has arrived. A draft, an archived post, and a scheduled post that is still in the future stay out.
- **Forum threads** (`forum_threads`). A published thread, including the text of its visible replies. A thread that is not published stays out, and so does a reply that is held or deleted. Boards are public, so there is no permission to apply. A board that later requires one is excluded in `ForumThread::shouldBeSearchable()`.
- **FAQs** (`faqs`). A published question, with its category's name.
- **Products** (`products`). A product that is for sale. An archived product stays out. The shop uses the same rule.

Saving a record that stops qualifying removes its document. A new or edited reply asks for the thread's document again, once an engine is in use. Taking down a member's posts in bulk (see [moderation.md](moderation.md)) skips those model events, so the clean-up removes the unpublished threads from the index and rebuilds every other thread that lost a reply.

The words stored are plain text, and a document stops at 8,000 characters.

## Not built

- The search page still uses the database, including a scheduled post whose time has arrived. A scheduled post is not added to the Meilisearch index by the clock: it is added when the post is saved after it is due, or when the index is rebuilt.
- Index settings, `metaforge:search:sync`, a queued rebuild, typo tolerance, and swapping the search page onto the index.
- A fallback to the database when Meilisearch cannot be reached, the ACP health check, and a CI job that runs Meilisearch.

## Testing

`tests/Feature/Search/SearchIndexTest.php` uses Scout's collection engine: a draft, an unpublished thread, an unpublished FAQ, an archived product and a held reply are left out, unpublishing a post removes its document, and taking down a member's posts removes their threads and reply text from the index.
