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
        'menu_title' => 'Navigation menu',
        'skip_to_content' => 'Skip to content',
        'settings' => 'Settings',
        'account_menu' => 'Account menu',
    ],

    'actions' => [
        'log_in' => 'Log in',
        'log_out' => 'Log out',
        'get_started' => 'Get started',
        'search' => 'Search',
        'search_placeholder' => 'Search…',
    ],

    // The cart in the header (the sheet that opens from the trolley) and the wishlist beside it.
    'cart' => [
        'open' => 'Open cart',
        'title' => 'Your cart',
        'description' => 'Review your items before checkout.',
        'empty' => 'Your cart is empty.',
        'base_product' => 'Base product',
        'line' => ':variant · Qty :quantity',
        'each' => ':price each',
        'subtotal' => 'Subtotal',
        'view' => 'View cart',
        'keep_shopping' => 'Keep shopping',
        'wishlist' => 'Wishlist',
        'your_wishlist' => 'Your wishlist',
    ],

    // The bell in the header and the list it opens (docs/notifications.md).
    'notifications' => [
        'title' => 'Notifications',
        'unread' => ':count unread notification|:count unread notifications',
        'mark_all_read' => 'Mark all read',
        'caught_up' => "You're all caught up.",
        'mark_read' => 'Mark read',
        'dismiss' => 'Dismiss',
        'showing_latest' => 'Showing the latest :shown of :unread unread notifications.',
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

    // The bar asking a member to replace recovery codes that are no longer a full set (docs/two-factor.md).
    'security' => [
        'recovery_prompt' => 'Your recovery codes are no longer a full set. Make a new set and store it somewhere safe.',
        'recovery_prompt_action' => 'Make new codes',
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
        'tagline' => 'A full-stack starter kit for modern SaaS and community apps.',
        'product' => 'Product',
        'community' => 'Community',
        'developers' => 'Developers',
        'api_docs' => 'API docs',
        'github' => 'GitHub',
        'rights' => '© :year :name. All rights reserved.',
    ],
];
