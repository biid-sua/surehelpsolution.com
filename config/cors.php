<?php

/*
|--------------------------------------------------------------------------
| CORS (spec §50)
|--------------------------------------------------------------------------
|
| Native mobile apps don't use CORS. Browsers may only call the API from our
| own site and any origins listed in CORS_ALLOWED_ORIGINS (comma-separated),
| e.g. a future embeddable chatbot host. Never "*".
|
*/

$origins = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))));

return [

    // The website chat widget (api/chat/*) checks each business's allowed sites itself (D37).
    'paths' => ['api/v1/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique(array_filter([rtrim((string) env('APP_URL'), '/'), ...$origins]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN'],

    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 3600,

    'supports_credentials' => true,

];
