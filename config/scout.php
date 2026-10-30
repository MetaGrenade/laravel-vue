<?php

use App\Support\Search\SearchIndexSettings;

return [
    /*
    | The switch is SEARCH_DRIVER in config/search.php. "meilisearch" is the
    | only value that talks to Meilisearch. The database driver, and anything
    | else, uses Scout's null engine so a save does not try to reach it.
    |
    | metaforge:search:sync applies index-settings. metaforge:search:reindex
    | flushes and re-imports documents. With SEARCH_DRIVER=meilisearch the
    | search page reads the index, and falls back to the database when
    | Meilisearch cannot be reached. metaforge:search:health says how it is.
    */

    'driver' => env('SEARCH_DRIVER', 'database') === 'meilisearch' ? 'meilisearch' : 'null',

    /*
    | Whether saving a record indexes it on the queue (a worker has to be
    | running) or while the request is handled. Done in the request, a
    | Meilisearch that is down makes the save fail after the record is stored;
    | on the queue the save succeeds and the job fails instead.
    | See "When Meilisearch is down" in docs/search-index.md.
    */

    'queue' => (bool) env('SCOUT_QUEUE', false),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => SearchIndexSettings::forScout(),
    ],
];
