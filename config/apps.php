<?php

/*
|--------------------------------------------------------------------------
| Connected apps (docs/decisions.md D110)
|--------------------------------------------------------------------------
|
| A business's own software connected to SureHelp. QuickBooks Online first: one Intuit app
| (developer.intuit.com) for every business, with its keys under Admin › Connections › Business apps.
| The 'fake' driver keeps everything in memory, for tests and demos.
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

];
