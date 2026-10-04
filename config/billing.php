<?php

return [
    // Payment provider (docs/billing.md). Only "payoneer" exists today.
    'gateway' => env('BILLING_GATEWAY', 'payoneer'),
];
