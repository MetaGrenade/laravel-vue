<?php

use App\Models\User;

return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),

    'model' => env('CASHIER_MODEL', User::class),

    'path' => env('CASHIER_PATH', 'billing'),

    'middleware' => ['web', 'auth'],

    'currency' => env('CASHIER_CURRENCY', 'usd'),

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en'),

    'payment_notification' => env('CASHIER_PAYMENT_NOTIFICATION', true),

    'logger' => env('CASHIER_LOGGER'),

    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'cli_secret' => env('STRIPE_CLI_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'jobs' => [
            'invoice.payment_succeeded' => null,
            'invoice.payment_failed' => null,
            'customer.subscription.deleted' => null,
            'customer.subscription.updated' => null,
        ],
    ],

    'invoice_data' => [],

    'stripe_options' => [
        'api_key' => env('STRIPE_SECRET'),
    ],
];
