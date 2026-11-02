<?php

/*
| "Hands Full", the missed-call game on the public website (D122). A short browser game: the visitor
| plays a plumber fixing leaks while the phone keeps ringing, then lets Sure Help's receptionist take the
| calls. Everything a brand might change lives here: names, words, colours, logo and the buttons at the end.
| Money in the game is made up; it never claims real results.
*/

return [

    'enabled' => (bool) env('GAME_ENABLED', true),

    // The page's address: surehelpsolution.com/<path>.
    'path' => 'missed-call-game',

    'title' => 'Hands Full: the missed-call game',
    'tagline' => 'You’re fixing a leak. The phone won’t stop ringing. How much work can you keep?',

    'brand' => [
        'name' => 'Sure Help',
        'logo' => 'assets/img/logo-on-light.png',     // under public/
        'receptionist' => 'Ava',
        // Colours as hex. ink = text and dark panels, accent = money earned and buttons, paper = background.
        'colors' => [
            'ink' => '#121633',
            'paper' => '#fbfaf6',
            'accent' => '#57ff8d',
            'accent_ink' => '#0b7a43',
            'loss' => '#e5484d',
            'highlight' => '#6d5efc',
        ],
    ],

    // Round lengths in seconds. The offer to switch on the receptionist appears after `offer_after`.
    'timing' => [
        'total' => 60,
        'offer_after' => 20,
    ],

    // The buttons on the results screen. `signup` and `contact` are route names or full URLs.
    'cta' => [
        'primary' => ['label' => 'Start your free trial', 'url' => 'signup'],
        'secondary' => ['label' => 'Book a demo', 'url' => 'home#contact'],
    ],

    // Tracking tags added to the buttons, so sign-ups from the game can be counted.
    'utm' => ['utm_source' => 'game', 'utm_medium' => 'website', 'utm_campaign' => 'hands-full'],
];
