<?php

/*
 * Strings used by the Vue front end (see config/i18n.php `shared_groups`).
 * Read them in components with `const { t } = useI18n(); t('ui.nav.home')`.
 */

return [
    'nav' => [
        'main' => 'Main',
        'home' => 'Home',
        'pricing' => 'Pricing',
        'shop' => 'Shop',
        'dashboard' => 'Dashboard',
        'blog' => 'Blog',
        'forum' => 'Forum',
        'members' => 'Members',
        'leaderboard' => 'Leaderboard',
        'admin' => 'Admin',
        'support' => 'Support',
        'repository' => 'Repository',
        'open_menu' => 'Open navigation menu',
        'skip_to_content' => 'Skip to content',
    ],

    'actions' => [
        'log_in' => 'Log in',
        'get_started' => 'Get started',
        'search' => 'Search',
        'search_placeholder' => 'Search…',
    ],

    'theme' => [
        'label' => 'Theme',
        'change' => 'Change colour theme',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],

    // The bar at the top of every page while staff are viewing the site as a customer (docs/impersonation.md).
    'impersonation' => [
        'viewing_as' => 'Viewing as :name',
        'read_only' => 'Read only: nothing can be changed.',
        'reason' => 'Reason: :reason',
        'ends_in' => 'Ends in :time',
        'stop' => 'Stop viewing',
    ],

    // The "Get started" checklist on the dashboard (docs/onboarding.md). The steps are those of config/onboarding.php.
    'onboarding' => [
        'title' => 'Get started',
        'description' => 'A few things worth doing first.',
        'progress' => ':done of :total done',
        'dismiss' => 'Hide this',
        'done' => 'Done',
        'steps' => [
            'verify_email' => [
                'title' => 'Confirm your email address',
                'description' => 'So we can reach you about orders and your account.',
                'action' => 'Confirm',
            ],
            'profile' => [
                'title' => 'Tell people about yourself',
                'description' => 'Add a picture or a few words about you.',
                'action' => 'Edit profile',
            ],
            'two_factor' => [
                'title' => 'Protect your account',
                'description' => 'Turn on two-factor authentication, so a stolen password is not enough.',
                'action' => 'Set up',
            ],
            'notifications' => [
                'title' => 'Choose what we email you',
                'description' => 'Pick the updates you want, and how you get them.',
                'action' => 'Choose',
            ],
            'plan' => [
                'title' => 'Choose a plan',
                'description' => 'See what each plan gives you.',
                'action' => 'See plans',
            ],
            'api_key' => [
                'title' => 'Make an API key',
                'description' => 'Use the API from your own tools.',
                'action' => 'Make a key',
            ],
            'community' => [
                'title' => 'Say hello in the forum',
                'description' => 'Start a thread or reply to one.',
                'action' => 'Open the forum',
            ],
        ],
    ],

    'footer' => [
        'tagline' => 'A production-ready Laravel and Vue foundation for SaaS products and online communities.',
        'product' => 'Product',
        'community' => 'Community',
        'developers' => 'Developers',
        'api_docs' => 'API docs',
        'github' => 'GitHub',
        'rights' => '© :year :name. All rights reserved.',
    ],
];
