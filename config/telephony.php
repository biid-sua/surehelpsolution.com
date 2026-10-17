<?php

/*
| Live calls (D10, D60). `driver` picks the TelephonyProvider: `twilio` in production, `fake` for local
| work, demos and tests (calls are simulated from the agent softphone; nothing leaves the app).
|
| Twilio needs: the Account SID and Auth Token (REST calls and webhook signatures), an API key and
| secret (browser phone tokens), and optionally a TwiML App SID (only for calls agents place).
| `webhook_url`: the public https address Twilio calls, when the app sits behind a proxy that
| changes it (signatures are computed over the exact public URL). See docs/telephony.md.
*/

return [

    'driver' => env('TELEPHONY_DRIVER', 'fake'),

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID', ''),
        'auth_token' => env('TWILIO_AUTH_TOKEN', ''),
        'api_key' => env('TWILIO_API_KEY', ''),
        'api_secret' => env('TWILIO_API_SECRET', ''),
        'twiml_app_sid' => env('TWILIO_TWIML_APP_SID', ''),
        'webhook_url' => env('TWILIO_WEBHOOK_URL', ''),
        'country' => env('TWILIO_NUMBER_COUNTRY', 'US'),
    ],

    // How long agents' browsers ring before the call goes to the owner or voicemail.
    'ring_seconds' => (int) env('TELEPHONY_RING_SECONDS', 20),

    // At most this many available agents ring at once (longest-waiting first).
    'max_ringing' => 5,

    // An agent counts as online while their softphone checked in within this many seconds.
    'presence_seconds' => 75,

    // After a call an agent has this long to finish the call log before calls ring again.
    'wrap_up_seconds' => 120,

    // Spoken before the call is connected when the line records calls.
    'disclosure' => 'Thanks for calling. This call may be recorded for quality and training.',

    'voicemail_prompt' => 'Sorry, nobody can take your call right now. Please leave your name, number and a short message after the tone.',
    'voicemail_seconds' => 120,

    // Texting (D61). US texts need an approved A2P 10DLC registration first, so this stays off until
    // then (the demo phone always texts: nothing is sent).
    'sms' => (bool) env('TELEPHONY_SMS', false),

    // SureHelp's own number that sends notification texts to people who chose them (D63).
    'notify_from' => env('TELEPHONY_NOTIFY_FROM', ''),

    // Live transcription of recorded calls (both sides), summarised by the AI after the call.
    'transcribe' => (bool) env('TELEPHONY_TRANSCRIBE', true),

];
