<?php

/*
|--------------------------------------------------------------------------
| Operations (docs/decisions.md D91)
|--------------------------------------------------------------------------
|
| Error alerts by email, the /health endpoint for uptime monitors, and nightly database backups.
|
*/

return [
    // Who hears about server errors. Empty: errors are only logged.
    'alert_email' => env('OPS_ALERT_EMAIL'),
    // The same error is emailed at most once in this many minutes; at most 20 alerts an hour.
    'alert_throttle_minutes' => (int) env('OPS_ALERT_THROTTLE', 15),

    // /health?token=… shows each check; without the token it only says ok or not.
    'health_token' => env('HEALTH_TOKEN'),

    'backups' => [
        // Copies also go to this filesystem disk (for example "s3"); "local" keeps them on the server only.
        'disk' => env('BACKUP_DISK', 'local'),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    ],
];
