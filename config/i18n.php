<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported interface languages
    |--------------------------------------------------------------------------
    |
    | Two-letter language codes the UI is translated into. MetaForge 1.0 ships
    | English only; to add a language, create lang/<code>/*.php files, add the
    | code here (or in APP_SUPPORTED_LOCALES) and run `php artisan lang:check`.
    |
    */

    'supported' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_SUPPORTED_LOCALES', 'en')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Front-end translation groups
    |--------------------------------------------------------------------------
    |
    | Groups from lang/<locale>/<group>.php that are sent to the browser with
    | every page and read through the useI18n() composable. Keep this list
    | short: everything here is part of each response.
    |
    */

    'shared_groups' => ['ui'],

    // Cookie that remembers a visitor's language choice.
    'cookie' => 'locale',
];
