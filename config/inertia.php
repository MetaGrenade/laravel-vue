<?php

return [
    'ssr' => [
        'enabled' => false,
        'url' => null,
    ],

    'title' => null,

    'root_view' => 'app',

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [
            resource_path('js/pages'),
            resource_path('js/Pages'),
        ],
        'page_extensions' => ['js', 'jsx', 'ts', 'tsx', 'vue'],
    ],
];
