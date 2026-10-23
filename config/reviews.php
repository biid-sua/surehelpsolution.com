<?php

/*
|--------------------------------------------------------------------------
| Reviews and reputation (docs/decisions.md D83)
|--------------------------------------------------------------------------
|
| Google reviews are read through the Business Profile connection (Social › Accounts). AI reply drafts
| are made only when someone asks for one, with a small model and a monthly cap per business.
|
*/

return [
    'ai_model' => env('REVIEWS_AI_MODEL'),                       // null: the configured default model
    'ai_monthly_drafts' => (int) env('REVIEWS_AI_MONTHLY_DRAFTS', 100),
    'low_rating' => 2,                                            // this many stars or fewer: a task to respond
    'first_import' => 200,                                        // reviews read the first time, for the history
];
