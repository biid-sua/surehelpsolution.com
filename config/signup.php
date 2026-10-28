<?php

/*
 * Self-serve sign-up from the website (D65, docs/signup.md).
 */
return [
    // Off: /start says sign-ups are closed and points to the contact form.
    'enabled' => (bool) env('SIGNUP_ENABLED', true),

    // Sign-up attempts allowed per IP address per hour.
    'per_hour' => (int) env('SIGNUP_PER_HOUR', 5),

    // Free trials from the website (D97). Calls our receptionists answer before the owner keeps a plan
    // (empty: no limit); after that, calls go to the business's own number or voicemail.
    'trial_calls' => env('SIGNUP_TRIAL_CALLS', 25),

    // Reminders before an unconfirmed trial ends, in days left.
    'trial_reminder_days' => [3, 1],

    // New trials start with sample customers, calls and bookings the owner can remove in one click.
    'sample_data' => (bool) env('SIGNUP_SAMPLE_DATA', true),
];
