<?php

/*
|--------------------------------------------------------------------------
| Call quality reviews (spec SUP-04, docs/decisions.md D27)
|--------------------------------------------------------------------------
|
| The scorecard supervisors fill in for a call. Each point is marked Met,
| Partly or Missed (or "Doesn't apply" where allowed). A review keeps a copy
| of the scorecard it was made with, so editing this list never changes past
| scores.
|
*/

return [

    'criteria' => [
        'greeting' => [
            'label' => 'Greeting',
            'description' => 'Answered with the business name and their own name, warm and on time.',
            'weight' => 1,
            'optional' => false,
        ],
        'accuracy' => [
            'label' => 'Accuracy',
            'description' => 'Caller details, reason and notes are complete and correct; answers matched the business\'s instructions.',
            'weight' => 2,
            'optional' => false,
        ],
        'booking_attempt' => [
            'label' => 'Booking attempt',
            'description' => 'Offered an appointment or next step whenever the caller had a need the business can meet.',
            'weight' => 2,
            'optional' => true,
        ],
        'tone' => [
            'label' => 'Tone',
            'description' => 'Friendly, patient and clear; no long silences; ended the call politely.',
            'weight' => 1,
            'optional' => false,
        ],
        'compliance' => [
            'label' => 'Compliance',
            'description' => 'Followed escalation rules, kept to the script where required, didn\'t share private information.',
            'weight' => 2,
            'optional' => false,
            // Missing this fails the review whatever the total.
            'critical' => true,
        ],
    ],

    // A review at or above this score passes.
    'pass_score' => 80,

    // Calls drawn each day per agent for review, from the agent's calls the day before.
    'sample_per_agent' => 1,

];
