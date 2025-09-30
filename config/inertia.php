<?php

return [
    'ssr' => [
        'enabled' => false,
        'url' => null,
    ],

    'title' => null,

    'root_view' => 'app',

    'page_paths' => [
        resource_path('js/pages'),
        resource_path('js/Pages'),
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],
];
