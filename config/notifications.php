<?php

return [

    /*
    | Delivery channels, in the order shown. Whether one works here is worked out at run time
    | (App\Support\NotificationChannels, D63): texts need TELEPHONY_NOTIFY_FROM and texting on;
    | push needs the Firebase service account (FCM_CREDENTIALS, FCM_PROJECT_ID).
    */
    'channels' => [
        'database' => ['label' => 'In-app'],
        'mail' => ['label' => 'Email'],
        'sms' => ['label' => 'Text message'],
        'push' => ['label' => 'Mobile push'],
    ],

];
