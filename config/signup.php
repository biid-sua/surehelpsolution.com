<?php

/*
 * Self-serve sign-up from the website (D65, docs/signup.md).
 */
return [
    // Off: /start says sign-ups are closed and points to the contact form.
    'enabled' => (bool) env('SIGNUP_ENABLED', true),

    // Sign-up attempts allowed per IP address per hour.
    'per_hour' => (int) env('SIGNUP_PER_HOUR', 5),
];
