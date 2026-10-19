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

    /*
    | Webhooks: how Tebex tells the shop a payment happened. Without them an order is never marked paid.
    |
    | The secret is shown once when the endpoint is added in the Tebex panel (Developers -> Webhooks -> Endpoints) and
    | signs every delivery. Tebex sends only from the two addresses below; a request from anywhere else is answered with
    | a 404. Switch the check off only when a proxy hides the sender's address and cannot be told to pass it on (the
    | signature is still checked).
    */

    'webhook' => [
        'secret' => env('TEBEX_WEBHOOK_SECRET'),
        'check_ip' => (bool) env('TEBEX_WEBHOOK_CHECK_IP', true),
        'ips' => ['18.209.80.3', '54.87.231.232'],
    ],

    /*
    | A basket is changed by one request at a time. Adding a package at Tebex ADDS to what the basket holds, so two
    | requests that both found it empty and both filled it (a buyer opening the sign-in link in two tabs) would put every
    | package in twice and send the buyer to pay double. A request that finds the basket in use waits this many seconds,
    | then gives up without touching it. (It needs a cache that supports locks, like the checkout's own lock.)
    */

    'basket' => [
        'lock_wait_seconds' => max(0, (int) env('TEBEX_BASKET_LOCK_WAIT', 10)),
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
