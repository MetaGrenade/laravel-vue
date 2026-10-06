<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site Identity
    |--------------------------------------------------------------------------
    |
    | Defaults used for page titles, Open Graph and structured data when a
    | page does not provide its own values.
    |
    */

    'site_name' => env('SEO_SITE_NAME') ?: env('APP_NAME', 'Laravel'),

    'description' => env('SEO_DESCRIPTION')
        ?: 'A modern platform with community forums, a blog, a support center and subscription billing.',

    // Home page copy. The site name is appended to the title automatically.
    'home' => [
        'title' => env('SEO_HOME_TITLE') ?: 'The production-ready starter kit for SaaS and communities',
        'description' => env('SEO_HOME_DESCRIPTION')
            ?: 'Launch faster with authentication, billing, a shop, forums, a blog, a support center and an admin control panel, built on Laravel and Vue.',
    ],

    // Absolute URL or path (relative to APP_URL) of the default social share image (1200x630).
    'image' => env('SEO_IMAGE') ?: null,

    'twitter_handle' => env('SEO_TWITTER_HANDLE'),

    'locale' => env('SEO_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Indexing
    |--------------------------------------------------------------------------
    |
    | Routes matching these names are marked "noindex, nofollow". Private and
    | low-value pages (account areas, auth flows, admin) should never appear
    | in search results. Set SEO_INDEXING=false to block indexing site-wide,
    | e.g. on staging environments.
    |
    */

    'indexing' => (bool) env('SEO_INDEXING', env('APP_ENV') === 'production'),

    'noindex_routes' => [
        'acp.*',
        'settings.*',
        'profile.*',
        'security.*',
        'privacy.*',
        'two-factor.*',
        'password.*',
        'verification.*',
        'login',
        'register',
        'logout',
        'dashboard',
        'notifications.*',
        'search.results',
        'blogs.preview',
        'shop.cart',
        'shop.orders',
        'support.tickets.*',
        'forum.threads.create',
        'forum.posts.history',
        'appearance',
        'oauth.*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap
    |--------------------------------------------------------------------------
    */

    'sitemap' => [
        'cache_ttl' => (int) env('SEO_SITEMAP_CACHE_TTL', 3600),
        'max_urls' => 45000,
    ],

];
