<?php

/*
|--------------------------------------------------------------------------
| Calendar sync (spec §17–19, docs/calendar-sync.md)
|--------------------------------------------------------------------------
|
| OAuth apps are registered once by SureHelp (not by each business). A provider
| without a client id is shown as "not available yet" and never contacted.
| Redirect URIs to register: {APP_URL}/app/integrations/calendar/{google|microsoft}/callback
|
*/

return [

    'providers' => [
        'google' => [
            'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
            // Least privilege (spec §18): read calendars and busy times, write only events.
            'scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/calendar.readonly', 'https://www.googleapis.com/auth/calendar.events'],
        ],
        'microsoft' => [
            'client_id' => env('MICROSOFT_CALENDAR_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_CALENDAR_CLIENT_SECRET'),
            'tenant' => env('MICROSOFT_CALENDAR_TENANT', 'common'),
            'scopes' => ['openid', 'email', 'offline_access', 'User.Read', 'Calendars.ReadWrite'],
        ],
    ],

    // Busy times are mirrored for this window; bookings beyond it aren't checked against external calendars.
    'sync_days_ahead' => (int) env('CALENDAR_SYNC_DAYS_AHEAD', 90),

    // Push notifications need a public HTTPS URL; polling (every 10 minutes) always runs as the safety net.
    'push_enabled' => (bool) env('CALENDAR_PUSH_ENABLED', true),

];
