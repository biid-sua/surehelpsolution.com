<?php

return [
    // Payment provider (docs/billing.md): "payoneer" (payments recorded by a person, D22) or
    // "stripe" (card payments and automatic payments confirmed by Stripe, D64).
    'gateway' => env('BILLING_GATEWAY', 'payoneer'),

    'stripe' => [
        'secret' => env('STRIPE_SECRET', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        // Stripe Connect (D85): businesses take deposits and payments from their customers into their own account.
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET', ''),
        'connect_fee_percent' => (float) env('STRIPE_CONNECT_FEE_PERCENT', 0),   // SureHelp's share of each payment, 0 for none
    ],
];
