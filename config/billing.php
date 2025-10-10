<?php

return [
    'subscription_name' => env('BILLING_SUBSCRIPTION_NAME', 'default'),
    'currency' => strtoupper(env('CASHIER_CURRENCY', 'usd')),
    'invoice_pagination' => env('BILLING_INVOICES_PER_PAGE', 25),
    'webhooks' => [
        'store_payloads' => env('BILLING_WEBHOOK_STORE', true),
    ],
    'plans' => [
        [
            'name' => 'Starter',
            'slug' => 'starter',
            'stripe_price' => env('STRIPE_PRICE_STARTER', 'price_starter'),
            'price' => 1200,
            'interval' => 'month',
            'features' => [
                'Access to community forums',
                'Monthly office hours',
                'Priority email support',
            ],
        ],
        [
            'name' => 'Pro',
            'slug' => 'pro',
            'stripe_price' => env('STRIPE_PRICE_PRO', 'price_pro'),
            'price' => 2900,
            'interval' => 'month',
            'features' => [
                'Everything in Starter',
                'Weekly strategy sessions',
                'Dedicated success manager',
            ],
        ],
    ],
];
