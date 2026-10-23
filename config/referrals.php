<?php

/*
| The referral programme (docs/decisions.md D90): one month of the referrer's plan as credit for each
| referred business that pays its first invoice.
*/

return [
    'enabled' => (bool) env('REFERRALS_ENABLED', true),
    'max_per_year' => (int) env('REFERRALS_MAX_PER_YEAR', 12),
];
