<?php

/*
|--------------------------------------------------------------------------
| AI (spec §33, §38; decisions D34, D38)
|--------------------------------------------------------------------------
|
| Without an API key every AI feature shows "coming soon" and nothing is sent anywhere.
|
*/

return [

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-opus-5-5'),
        // low | medium | high | xhigh | max. Claude Opus 5.5 defaults to medium; set explicitly.
        'effort' => env('AI_EFFORT', 'medium'),
        // Thinking counts towards this; generous so replies are never cut off.
        'max_tokens' => (int) env('AI_MAX_TOKENS', 16000),
        // Anthropic retries a declined request on its default fallback model (server-side, beta).
        'fallbacks' => (bool) env('AI_FALLBACKS', true),
    ],

    'assistant' => [
        // Tool rounds per reply before the conversation is handed to a person.
        'max_steps' => 6,
        // AI replies per conversation per hour (stops loops with another bot).
        'max_replies_per_hour' => 20,
        // Messages of history sent with each request.
        'history' => 40,
        // Characters of knowledge base text included in the instructions.
        'knowledge_chars' => 24000,
    ],

];
