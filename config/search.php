<?php

return [
    'driver' => env('SEARCH_DRIVER', 'database'),

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
