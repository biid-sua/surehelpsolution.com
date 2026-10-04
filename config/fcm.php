<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging Credentials
    |--------------------------------------------------------------------------
    |
    | Store the server key and sender id in the environment so we can
    | authenticate calls to the FCM REST API without committing secrets.
    |
    */
    'server_key' => env('FCM_SERVER_KEY'),
    'sender_id' => env('FCM_SENDER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Default Notification Settings
    |--------------------------------------------------------------------------
    */
    'default_sound' => env('FCM_DEFAULT_SOUND', 'default'),
    'android_channel_id' => env('FCM_ANDROID_CHANNEL_ID', 'default-channel'),
];
