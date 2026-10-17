<?php

return [

    /*
    | Firebase Cloud Messaging, HTTP v1 (D63). `credentials`: the Firebase service account JSON
    | (Project settings › Service accounts › Generate new private key) as a file path, the JSON
    | itself, or base64 of it. `project_id` defaults to the one in that file. Keep it out of git.
    */
    'credentials' => env('FCM_CREDENTIALS', ''),
    'project_id' => env('FCM_PROJECT_ID', ''),

    'default_sound' => env('FCM_DEFAULT_SOUND', 'default'),
    'android_channel_id' => env('FCM_ANDROID_CHANNEL_ID', 'default-channel'),

];
