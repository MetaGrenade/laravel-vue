<?php

/*
 * The frame of the settings pages: the title and the navigation beside them (resources/js/layouts/settings/SettingsLayout.vue). Sent to
 * the browser only with those pages (`translations:settings` in routes/settings.php), not with every page (docs/i18n.md). The words
 * inside each settings page are still in English; they follow in 1.1.
 */

return [
    'title' => 'Settings',
    'description' => 'Manage your profile, security and billing.',
    'sections' => 'Settings sections',

    'groups' => [
        'account' => 'Account',
        'billing' => 'Billing',
    ],

    'nav' => [
        'profile' => 'Profile',
        'password' => 'Password',
        'security' => 'Security',
        'api_keys' => 'API keys',
        'notifications' => 'Notifications',
        'discord_notifications' => 'Discord notifications',
        'privacy' => 'Privacy',
        'account_standing' => 'Account standing',
        'appearance' => 'Appearance',
        'subscription' => 'Subscription',
        'payment_methods' => 'Payment methods',
        'invoices' => 'Invoices',
        'addresses' => 'Addresses',
    ],
];
