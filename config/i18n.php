<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported interface languages
    |--------------------------------------------------------------------------
    |
    | Two-letter language codes the UI is translated into. MetaForger 1.0 ships
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
    | The site's own language
    |--------------------------------------------------------------------------
    |
    | The language used when nothing asks for another one: a visitor with no
    | preference, a guest's order email, a member whose language the site does
    | not have. It is APP_LOCALE, read once here. `app.locale` cannot be asked
    | instead: Laravel's App::setLocale() writes the request's language into it,
    | so after SetLocale (or while an email is being made in someone's language)
    | it is the language in use, not the site's.
    |
    */

    'default' => env('APP_LOCALE', 'en'),

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

    'shared_groups' => ['ui', 'members', 'legal'],

    // Cookie that remembers a visitor's language choice.
    'cookie' => 'locale',
];
