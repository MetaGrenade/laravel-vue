<?php

return [
    'subscription_name' => env('BILLING_SUBSCRIPTION_NAME', 'default'),
    'currency' => strtoupper(env('CASHIER_CURRENCY', 'usd')),
    'invoice_pagination' => env('BILLING_INVOICES_PER_PAGE', 25),
    'webhooks' => [
        'store_payloads' => env('BILLING_WEBHOOK_STORE', true),
    ],

    /*
    | What the example plans below can give. Seeded (never overwritten) next to the plans; replace them with your own, or
    | manage the real ones in Commerce → Entitlements. Code asks for a key: `$user->canUse('priority-support')`,
    | `$user->limitOf('projects')`, `entitled:priority-support` on a route. See docs/entitlements.md.
    */
    'entitlements' => [
        'projects' => ['label' => 'Projects', 'type' => 'limit', 'description' => 'How many projects an account may have.'],
        'priority-support' => ['label' => 'Priority support', 'type' => 'feature', 'description' => 'Questions are answered first.'],
    ],

    /*
    | `entitlements` is what being on the plan gives: a feature (`true`) or a limit (an amount), by the keys above.
    */
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
            'entitlements' => ['projects' => 3],
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
            'entitlements' => ['projects' => 25, 'priority-support' => true],
        ],
    ],
];
