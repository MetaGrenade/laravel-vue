<?php

use App\Support\Search\SearchIndexSettings;

return [
    /*
    | The switch is SEARCH_DRIVER in config/search.php. "meilisearch" is the
    | only value that talks to Meilisearch. The database driver, and anything
    | else, uses Scout's null engine so a save does not try to reach it.
    |
    | metaforge:search:sync applies index-settings only. Importing documents
    | is a later command.
    */

    'driver' => env('SEARCH_DRIVER', 'database') === 'meilisearch' ? 'meilisearch' : 'null',

    'queue' => false,

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => SearchIndexSettings::forScout(),
    ],
];
