<?php

/*
|--------------------------------------------------------------------------
| Social publishing (spec §41, docs/social.md, decision D33)
|--------------------------------------------------------------------------
|
| SureHelp registers one app per network; businesses connect their own accounts through it.
| A network without credentials is shown as "coming soon" and never contacted.
| Redirect URIs to register: {APP_URL}/app/social/connect/{meta|linkedin|google}/callback
|
*/

return [

    'connectors' => [
        // One Facebook Login app covers Facebook Pages and the Instagram accounts linked to them.
        'meta' => [
            'client_id' => env('META_APP_ID'),
            'client_secret' => env('META_APP_SECRET'),
            'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
            'scopes' => [
                'pages_show_list', 'pages_read_engagement', 'pages_manage_posts',
                'instagram_basic', 'instagram_content_publish', 'business_management',
                // Inbox (D37): Messenger and Instagram direct messages.
                'pages_messaging', 'pages_manage_metadata', 'instagram_manage_messages',
            ],
            // Any long random string; entered in the Meta app's webhook settings (docs/inbox.md).
            'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        ],
        'linkedin' => [
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
            // YYYYMM, sent as the LinkedIn-Version header on every REST call.
            'api_version' => env('LINKEDIN_API_VERSION', '202609'),
            'scopes' => ['openid', 'profile', 'email', 'w_member_social', 'w_organization_social', 'r_organization_social', 'rw_organization_admin'],
        ],
        'google' => [
            'client_id' => env('GOOGLE_BUSINESS_CLIENT_ID', env('GOOGLE_CALENDAR_CLIENT_ID')),
            'client_secret' => env('GOOGLE_BUSINESS_CLIENT_SECRET', env('GOOGLE_CALENDAR_CLIENT_SECRET')),
            // Business Profile API access must be granted to the Google Cloud project first (docs/social.md).
            'enabled' => (bool) env('GOOGLE_BUSINESS_ENABLED', false),
            'scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/business.manage'],
        ],
    ],

    // How far ahead a post can be scheduled.
    'max_schedule_days' => (int) env('SOCIAL_MAX_SCHEDULE_DAYS', 365),

    // Failed attempts are retried after these many minutes, then the post is marked failed.
    'retry_minutes' => [5, 15, 60],

    // Uploads.
    'media' => [
        'max_kb' => 8192,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        // Networks download images from a signed link valid for this long.
        'link_minutes' => 120,
    ],

];
