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

    // Links that open the app when it's installed (API-16): Android App Links and iOS Universal Links.
    // Leave empty until the store builds exist; the files then say "no app".
    'app_links' => [
        'android_package' => env('MOBILE_ANDROID_PACKAGE', 'surehelpsolution.com.shs_mobile_app'),
        // SHA-256 signing-certificate fingerprints from Play Console › App integrity (comma-separated).
        'android_sha256' => array_values(array_filter(array_map('trim', explode(',', (string) env('MOBILE_ANDROID_SHA256', ''))))),
        // "TEAMID.bundle.id" entries (comma-separated).
        'ios_app_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('MOBILE_IOS_APP_IDS', ''))))),
        // Pages the app can show; everything else stays in the browser.
        'paths' => ['/app/*', '/agent/*', '/invitations/*', '/account/*', '/b/*'],
    ],
];
