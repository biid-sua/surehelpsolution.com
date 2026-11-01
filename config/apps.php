<?php

/*
|--------------------------------------------------------------------------
| Connected apps (docs/decisions.md D110)
|--------------------------------------------------------------------------
|
| A business's own software connected to SureHelp: QuickBooks Online (D110), Jobber (D111) and
| Housecall Pro (D112). QuickBooks and Jobber use one developer app for every business, with its keys
| under Admin › Connections › Business apps; Housecall Pro uses the business's own API key. The
| 'fake' drivers keep everything in memory, for tests and demos.
|
*/

return [

    'quickbooks' => [
        'driver' => env('QUICKBOOKS_DRIVER', 'intuit'),
        'client_id' => env('QUICKBOOKS_CLIENT_ID'),
        'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
        // 'sandbox' while testing with Intuit's sample companies.
        'environment' => env('QUICKBOOKS_ENVIRONMENT', 'production'),
        'minor_version' => 75,
        // Customers brought in from QuickBooks per run (the rest follow on the next run).
        'pull_limit' => (int) env('QUICKBOOKS_PULL_LIMIT', 2000),
    ],

    'jobber' => [
        'driver' => env('JOBBER_DRIVER', 'jobber'),
        'client_id' => env('JOBBER_CLIENT_ID'),
        'client_secret' => env('JOBBER_CLIENT_SECRET'),
        // Jobber dates its GraphQL API; a request names the version it was written for.
        'api_version' => env('JOBBER_API_VERSION', '2025-01-20'),
        'pull_limit' => (int) env('JOBBER_PULL_LIMIT', 2000),
    ],

    'housecall_pro' => [
        'driver' => env('HOUSECALL_PRO_DRIVER', 'housecall_pro'),
        'base_url' => env('HOUSECALL_PRO_BASE_URL', 'https://api.housecallpro.com'),
        // Housecall Pro can't list only changed customers, so each run reads up to this many.
        'pull_limit' => (int) env('HOUSECALL_PRO_PULL_LIMIT', 2000),
    ],

];
