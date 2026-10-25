<?php

/*
|--------------------------------------------------------------------------
| Moderation (docs/moderation.md)
|--------------------------------------------------------------------------
*/

return [

    /*
    | Where a member can ask about a warning or a ban: an email address or a web address. It is added to the emails they are sent and
    | to what a banned member is told when they try to sign in. Left empty, nothing is said about asking.
    */

    'contact' => env('MODERATION_CONTACT'),

];
