<?php

/*
|--------------------------------------------------------------------------
| Tebex
|--------------------------------------------------------------------------
|
| Settings for selling through a Tebex store (https://tebex.io). Tebex keeps the catalogue and takes the payment;
| the shop reads the catalogue from a local copy that `php artisan tebex:sync` keeps up to date. See docs/tebex.md.
|
| The public token identifies the store and is not a secret (it appears in every storefront's requests). The private
| key and the webhook secret are secrets: they live only in the environment, are used only on the server, and are
| never sent to the browser or written to the logs.
|
*/

return [

    /*
    | The store's public token, from the Tebex panel: Developers -> API Keys. It starts with four characters and a
    | dash, for example "spk3-d515a30...". Nothing works without it.
    */

    'public_token' => env('TEBEX_PUBLIC_TOKEN'),

    /*
    | The store's private key, from the same page. Only calls that return a customer's own data need it, so it is
    | optional until a feature that does is switched on.
    */

    'private_key' => env('TEBEX_PRIVATE_KEY'),

    /*
    | The Headless API. Change it only to point at a test double.
    */

    'api_url' => env('TEBEX_API_URL', 'https://headless.tebex.io/api'),

    'http' => [
        // Seconds to wait for an answer, and to wait for a connection.
        'timeout' => max(1, (int) env('TEBEX_HTTP_TIMEOUT', 10)),
        'connect_timeout' => max(1, (int) env('TEBEX_HTTP_CONNECT_TIMEOUT', 5)),
        // A request that fails on the network, with a 5xx or with a 429 is repeated after each of these waits
        // (milliseconds), so it is tried once more than there are entries. Anything else is not retried.
        'retry_backoff_ms' => [500, 1500, 4000],
    ],

    'sync' => [
        // How often the scheduler refreshes the local copy of the catalogue, in minutes, when Tebex is configured
        // (it is checked every five minutes, so nothing under five is useful). 0 turns the schedule off: the command
        // and the ACP button still work.
        'every_minutes' => (int) env('TEBEX_SYNC_EVERY_MINUTES', 60) > 0 ? max(5, (int) env('TEBEX_SYNC_EVERY_MINUTES', 60)) : 0,
        // How many past synchronisations the ACP keeps to show.
        'keep_runs' => 50,
    ],

];
