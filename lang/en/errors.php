<?php

/*
 * Copy for the error pages: the standalone view (resources/views/errors) that a full page load gets, and the Error page that a visit
 * inside the app gets (App\Support\Errors\ErrorPages, docs/error-pages.md).
 * These are Laravel translations, not part of the Inertia shared groups; the Error page is handed the words it shows.
 */

return [
    'home' => 'Go to the homepage',
    'back' => 'Go back',
    'retry' => 'Try again',
    'retry_in_seconds' => 'Try again in a second.|Try again in :seconds seconds.',
    'retry_in_minutes' => 'Try again in a minute.|Try again in :minutes minutes.',
    'hints' => [
        '401' => 'Sign in to open this page.',
        '402' => 'Payment is required before this page will open.',
        '403' => 'Your account cannot open this page.',
        '404' => 'This page is not on the site.',
        '419' => 'This page expired. Go back and try again.',
        '429' => 'Wait a moment, then try again.',
        '500' => 'Something went wrong on our side. Try again in a moment.',
        '503' => 'The site is briefly unavailable. Try again in a moment.',
        'default' => 'Go back and try again.',
    ],
    // What a notice says when something somebody did (a form, a button) was refused: they stay on the page they were on.
    'actions' => [
        '401' => 'Sign in to do that.',
        '402' => 'Payment is required before you can do that.',
        '403' => 'Your account cannot do that.',
        '404' => 'That is no longer there. It may have been removed.',
        '419' => 'Your session ended, so that was not saved. Try again.',
        '429' => 'Too many attempts. Wait a moment, then try again.',
        '500' => 'Something went wrong on our side. Try again in a moment.',
        '503' => 'The site is briefly unavailable. Try again in a moment.',
    ],
];
