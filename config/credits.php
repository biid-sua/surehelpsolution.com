<?php

/*
 * AI credits (D100, docs/ai-credits.md). Credits pay for AI work beyond a business's monthly
 * allowance, starting with the website builder.
 */
return [
    // AI tokens one credit buys. A website change usually uses 2,000 to 5,000 tokens: 2 to 5 credits.
    'tokens_per_credit' => (int) env('CREDITS_TOKENS_PER_CREDIT', 1000),

    // Packs on sale until staff change them in Admin › Billing › AI credits.
    'packs' => [
        ['credits' => 100, 'price_cents' => 1000],
        ['credits' => 500, 'price_cents' => 4000],
        ['credits' => 2000, 'price_cents' => 12000],
    ],

    'max_packs' => 6,
];
