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

    // Which AI writes for SureHelp's features: claude or openai (ChatGPT). Admin › Connections can
    // change this and the keys below without touching .env (D104).
    'provider' => env('AI_PROVIDER', 'claude'),

    // SureHelp's own social posts can use the other one (null: same as `provider`).
    'social_provider' => env('AI_SOCIAL_PROVIDER'),

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

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        // Pick from your account's list under Admin › Connections.
        'model' => env('OPENAI_MODEL'),
        // For pictures in social posts; only ChatGPT makes pictures.
        'image_model' => env('OPENAI_IMAGE_MODEL'),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 16000),
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

    // AI help for agents during calls (D88): answers to read out and the call form filled from notes.
    // A fast model keeps answers quick while the caller waits, e.g. AGENT_ASSIST_MODEL=claude-haiku-5-5.
    'agent_assist_model' => env('AGENT_ASSIST_MODEL'),
    'agent_assist_daily' => (int) env('AGENT_ASSIST_DAILY', 300),   // per business

    // The AI receptionist on calls (D108): replies must come back within a few seconds, so it thinks
    // briefly. Leave the model empty to use the main one.
    'receptionist' => [
        'effort' => env('AI_RECEPTIONIST_EFFORT', 'low'),
        'model' => env('AI_RECEPTIONIST_MODEL'),
    ],

];
