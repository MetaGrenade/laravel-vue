<?php

return [
    // 'database' searches with case-insensitive LIKE and works on MySQL, PostgreSQL and SQLite.
    // 'meilisearch' searches the Scout indexes (blogs, forum threads, FAQs). See docs/search-index.md.
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

    // When SEARCH_DRIVER=meilisearch and the host is unreachable, search falls
    // back to the database for this many seconds without retrying every request.
    'meilisearch_fallback' => [
        'circuit_seconds' => (int) env('SEARCH_MEILISEARCH_CIRCUIT_SECONDS', 60),
        'cache_key' => env('SEARCH_MEILISEARCH_CIRCUIT_CACHE_KEY', 'search:meilisearch:circuit_open'),
    ],
];
