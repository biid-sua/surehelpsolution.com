<?php

/*
|--------------------------------------------------------------------------
| SEO service (docs/decisions.md D57)
|--------------------------------------------------------------------------
|
| AI tokens a business may use on SEO work each calendar month, unless its plan sets the
| `seo_ai_tokens` limit or staff set an amount for that business. Tokens are what the AI model
| reads and writes; a typical task uses 5,000–20,000.
|
*/

return [

    'monthly_tokens' => (int) env('SEO_MONTHLY_TOKENS', 250000),

    // A task is refused when fewer than this many tokens are left this month.
    'min_tokens_to_start' => 5000,

    // Effort for SEO writing: quality matters more than speed, and nobody is waiting on a reply.
    'effort' => env('SEO_AI_EFFORT', 'high'),

    // Google Search Console (read-only), through the same Google app as calendar sync.
    'search_console' => [
        'scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/webmasters.readonly'],
        'days' => 480,   // history kept and fetched on connect (~16 months, Search Console's maximum)
    ],

];
