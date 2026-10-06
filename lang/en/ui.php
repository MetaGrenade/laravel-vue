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
