<?php

return [
    'channels' => [
        'mail' => [
            'label' => 'Email',
            'description' => 'Receive updates at your verified email address.',
            'default' => true,
        ],
        'push' => [
            'label' => 'Browser alerts',
            'description' => 'Receive real-time notifications while you are signed in.',
            'default' => true,
        ],
        'database' => [
            'label' => 'In-app',
            'description' => 'See updates in the notification center.',
            'default' => true,
        ],
    ],
    'categories' => [
        'blogs' => [
            'label' => 'Blog activity',
            'description' => 'Comments and replies on blogs you follow or author.',
            'channels' => ['mail', 'push', 'database'],
        ],
        'forums' => [
            'label' => 'Forum updates',
            'description' => 'Mentions and thread activity in the forums.',
            'channels' => ['mail', 'push', 'database'],
        ],
        'support' => [
            'label' => 'Support desk',
            'description' => 'Updates to tickets you opened or are assigned to.',
            'channels' => ['mail', 'push', 'database'],
        ],
        'privacy' => [
            'label' => 'Privacy tools',
            'description' => 'Data exports and account privacy actions.',
            'channels' => ['mail', 'push', 'database'],
        ],
    ],
];
