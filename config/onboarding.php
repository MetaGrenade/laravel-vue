<?php

/*
|--------------------------------------------------------------------------
| The "Get started" checklist (docs/onboarding.md)
|--------------------------------------------------------------------------
|
| A card at the top of a new customer's dashboard that lists what to do first. Each step ticks itself when the account shows it is
| done, and a step that does not apply to this site (no plans on sale, the forum switched off) is not listed.
|
*/

return [

    /*
    | Whether the checklist is shown at all.
    */

    'enabled' => (bool) env('ONBOARDING_ENABLED', true),

    /*
    | How many days after an account is made the checklist is shown (0: for as long as there is something left to do). It is also
    | what keeps it from appearing to every account that existed before it was built.
    */

    'days' => (int) env('ONBOARDING_DAYS', 30),

    /*
    | The steps, in the order they are listed. Take one out to drop it, or add one in `App\Support\Onboarding\Onboarding`.
    |
    |   verify_email   Confirm the email address (only where that is not required to sign in, as it is then done by being here).
    |   profile        A picture or a few words about themselves.
    |   two_factor     Turn on two-factor authentication.
    |   notifications  Choose what is emailed.
    |   plan           Choose a plan (only while plans are on sale).
    |   api_key        Make an API key.
    |   community      Write a first forum post (only while the forum is on).
    */

    'steps' => [
        'verify_email',
        'profile',
        'two_factor',
        'notifications',
        'plan',
        'api_key',
        'community',
    ],

];
