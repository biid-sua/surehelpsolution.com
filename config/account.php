<?php

/*
|--------------------------------------------------------------------------
| Account security and access (decisions D8, D24)
|--------------------------------------------------------------------------
*/

return [

    // Two-step sign-in (authenticator app). Mandatory for these portal types, optional for the rest.
    'two_factor' => [
        'required_for' => ['admin', 'agent'],
        'issuer' => env('TWO_FACTOR_ISSUER', 'SureHelp'),
        'recovery_codes' => 8,
    ],

    // Minutes without activity before a web session ends. Null: only the normal session lifetime applies.
    'idle_timeout' => [
        'admin' => (int) env('IDLE_TIMEOUT_STAFF', 30),
        'agent' => (int) env('IDLE_TIMEOUT_AGENTS', 30),
        'client' => null,
    ],

    // Team invitations from business owners.
    'invitation_days' => 7,

    // Documents people accept before using the product. Bump a version to ask everyone again.
    'legal' => [
        'terms' => ['label' => 'Terms of Use', 'version' => '2026-10-01', 'route' => 'legal.terms-of-use', 'roles' => ['admin', 'agent', 'client']],
        'privacy' => ['label' => 'Privacy Policy', 'version' => '2026-10-01', 'route' => 'legal.privacy-policy', 'roles' => ['admin', 'agent', 'client']],
        'dpa' => ['label' => 'Data Processing Addendum', 'version' => '2026-10-01', 'route' => 'legal.data-processing-addendum', 'roles' => ['client']],
    ],

];
