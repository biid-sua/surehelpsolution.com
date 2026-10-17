<?php

/*
 * The SureHelp mobile app (D71, docs/mobile-app-plan.md): which versions may still sign in, and where
 * to update. Raise `minimum` to retire old builds (they show "Please update"); `latest` shows a gentle prompt.
 */
return [
    'ios' => [
        'minimum' => env('MOBILE_IOS_MINIMUM', '1.0.0'),
        'latest' => env('MOBILE_IOS_LATEST', '1.0.0'),
        'store_url' => env('MOBILE_IOS_STORE_URL'),
    ],
    'android' => [
        'minimum' => env('MOBILE_ANDROID_MINIMUM', '1.0.0'),
        'latest' => env('MOBILE_ANDROID_LATEST', '1.0.0'),
        'store_url' => env('MOBILE_ANDROID_STORE_URL'),
    ],

    // How long a signed call-recording link for the app stays valid.
    'recording_link_minutes' => (int) env('MOBILE_RECORDING_LINK_MINUTES', 15),
];
