<?php

return [

    /*
    | Delivery channels. "enabled" channels can be chosen by users today; the
    | others are shown as coming soon until their provider is connected
    | (SMS: Twilio + A2P 10DLC, push: FCM HTTP v1 — docs/decisions.md D10).
    */
    'channels' => [
        'database' => ['label' => 'In-app', 'enabled' => true],
        'mail' => ['label' => 'Email', 'enabled' => true],
        'sms' => ['label' => 'Text message', 'enabled' => false],
        'push' => ['label' => 'Mobile push', 'enabled' => false],
    ],

];
