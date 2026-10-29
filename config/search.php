<?php

return [
    // 'database' searches with case-insensitive LIKE and works on MySQL, PostgreSQL and SQLite.
    // 'meilisearch' is the only other driver. It is read by config/scout.php.
    // The search page still uses the database path. See docs/search-index.md.
    'driver' => env('SEARCH_DRIVER', 'database'),

    'documents' => [
        'max_characters' => 8000,
    ],

    'highlight' => [
        'context_chars' => (int) env('SEARCH_HIGHLIGHT_CONTEXT_CHARS', 80),
    ],

    'queries' => [
        'retention_days' => (int) env('SEARCH_QUERY_RETENTION_DAYS', 90),
        'aggregation_window_days' => (int) env('SEARCH_QUERY_AGGREGATION_WINDOW_DAYS', 30),
        'top_queries_limit' => (int) env('SEARCH_TOP_QUERIES_LIMIT', 5),
        'top_zero_queries_limit' => (int) env('SEARCH_TOP_ZERO_QUERIES_LIMIT', 5),
        'minimum_length' => (int) env('SEARCH_QUERY_MIN_LENGTH', 2),
    ],
];
