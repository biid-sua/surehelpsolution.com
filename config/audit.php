<?php

return [

    /*
    | Audit entries older than this are pruned daily (spec §56: don't keep
    | everything forever without a reason). 0 disables pruning.
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 730),

    /*
    | Attribute names whose values are never written to the audit log (spec §58).
    | Matching is case-insensitive and also applies to nested keys.
    */
    'redact' => [
        'password', 'password_confirmation', 'current_password', 'remember_token',
        'token', 'access_token', 'refresh_token', 'secret', 'api_key', 'two_factor_secret',
        'two_factor_recovery_codes', 'card_number', 'cvc',
    ],

];
