<?php

return [
    // Payment provider (docs/billing.md): "payoneer" (payments recorded by a person, D22) or
    // "stripe" (card payments and automatic payments confirmed by Stripe, D64).
    'gateway' => env('BILLING_GATEWAY', 'payoneer'),

    'stripe' => [
        'secret' => env('STRIPE_SECRET', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    ],
];
