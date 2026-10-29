<?php

return [
    /*
    | The switch is SEARCH_DRIVER in config/search.php. "meilisearch" is the
    | only value that talks to Meilisearch. The database driver, and anything
    | else, uses Scout's null engine so a save does not try to reach it.
    |
    | Index settings, a queued sync and a rebuild are not part of this yet.
    */

    'driver' => env('SEARCH_DRIVER', 'database') === 'meilisearch' ? 'meilisearch' : 'null',

    'queue' => false,
];
