<?php

return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'path' => env('CASHIER_PATH', 'billing'),
    'currency' => env('CASHIER_CURRENCY', 'usd'),
    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],
];
