<?php

/*
|--------------------------------------------------------------------------
| SureHelp's own sales chat on the website (D78, docs/sales-chat.md)
|--------------------------------------------------------------------------
|
| A chat on the marketing site that answers questions, captures leads and books demos. Buttons,
| forms and the booking picker never call the AI; the AI only reads free-text questions, with a
| small cached prompt, short history and hard caps so a visitor (or a bot) can't run up a bill.
| Without an API key, or once the daily budget is spent, the chat keeps working on buttons and forms.
|
*/

return [

    'enabled' => (bool) env('SALES_CHAT_ENABLED', true),

    'assistant' => env('SALES_CHAT_ASSISTANT', 'Ava'),

    // Where lead and booking alerts go (defaults to the contact form's inbox).
    'notify' => env('SALES_CHAT_NOTIFY', env('MAIL_CONTACT_TO')),

    'ai' => [
        // Defaults to the app's model. claude-haiku-5-5 costs a small fraction and suits this chat well.
        'model' => env('SALES_CHAT_MODEL'),
        'effort' => env('SALES_CHAT_EFFORT', 'low'),
        'max_tokens' => (int) env('SALES_CHAT_MAX_TOKENS', 2000),
        // Messages of history sent with each question (the lead's details travel separately, as facts).
        'history' => 12,
        // Tool rounds per answer.
        'max_steps' => 4,
        // AI answers per chat, ever, and chats a visitor IP may start per day.
        'replies_per_chat' => (int) env('SALES_CHAT_REPLIES_PER_CHAT', 30),
        'chats_per_ip_per_day' => (int) env('SALES_CHAT_CHATS_PER_IP', 10),
        // Input + output tokens across all chats per day; past it, the chat answers from buttons and forms.
        'daily_token_budget' => (int) env('SALES_CHAT_DAILY_TOKENS', 2_000_000),
    ],

    'max_message_chars' => 600,

    // Demo calls with the SureHelp team.
    'demos' => [
        'timezone' => env('SALES_DEMO_TIMEZONE', 'America/New_York'),
        // ISO weekday (1 = Monday) => [start, end] in the timezone above.
        'hours' => [1 => ['09:00', '17:00'], 2 => ['09:00', '17:00'], 3 => ['09:00', '17:00'], 4 => ['09:00', '17:00'], 5 => ['09:00', '16:00']],
        'minutes' => 30,
        'notice_hours' => 2,
        'days_ahead' => 14,
        // Demos that can run at the same time (people on the sales team).
        'capacity' => (int) env('SALES_DEMO_CAPACITY', 1),
        // Where the call happens; shown in the invitation.
        'location' => env('SALES_DEMO_LOCATION', 'Phone or video call; we\'ll send the link before the call'),
        // The meeting link for every demo (D96), for example a recurring Microsoft Teams meeting
        // ("Join the meeting" link). When set, invitations, reminders and the calendar file carry it.
        'meeting_url' => env('SALES_DEMO_MEETING_URL'),
        // Follow-ups (D96): reminders before the call, and a nudge this many days after a demo
        // when they haven't started a trial.
        'remind_hours' => [24, 1],
        'nudge_after_days' => 3,
    ],

    // Fallback for the assistant when no plan is shown on the website yet. Normally the chat reads the
    // plans from Admin › Billing › Plans (D98, App\Services\Sales\SalesPlans).
    'plans' => [
        ['name' => 'Essential', 'monthly' => 300, 'annual_monthly' => 270, 'minutes' => '100 minutes a month', 'best_for' => 'solo professionals and coaches',
            'includes' => '24/7 answering, appointment booking, SMS and email notifications, client portal and app, message taking, follow-up on up to 50 calls a month', 'extra' => '$2.00 per extra call'],
        ['name' => 'Professional', 'monthly' => 400, 'annual_monthly' => 360, 'minutes' => '150 minutes a month', 'best_for' => 'home services and growing businesses',
            'includes' => 'everything in Essential, custom call scripts, follow-up on every call, monthly performance reports, priority notifications, advanced analytics', 'extra' => '$1.50 per extra call'],
        ['name' => 'Growth', 'monthly' => 450, 'annual_monthly' => 405, 'minutes' => '200 minutes a month', 'best_for' => 'medical, legal and financial practices',
            'includes' => 'everything in Professional, a dedicated account manager, advanced reporting, priority support and white-label options, priority routing', 'extra' => '$1.25 per extra call'],
        ['name' => 'Enterprise', 'monthly' => null, 'annual_monthly' => null, 'minutes' => 'custom minutes', 'best_for' => 'franchises and multi-location businesses',
            'includes' => 'everything in Growth, dedicated teams, custom integrations, white-glove onboarding, guaranteed SLAs, volume pricing', 'extra' => 'tailored'],
    ],

    'facts' => [
        'No setup fees; cancel any time; 30-day money-back guarantee.',
        'Every plan starts with a free trial at /start (self-serve sign-up).',
        'Live in about 48 hours: we set up the script, rules and calendar, then the business forwards its line.',
        'Real, trained receptionists answer 24/7 in the business\'s name; an AI assistant can also answer website chat, Facebook, Instagram and texts.',
        'Google Calendar and Microsoft 365 / Outlook two-way sync. A client portal and iPhone / Android app show every call within seconds.',
        'HIPAA compliant; a Business Associate Agreement is available for healthcare.',
    ],

    // Chats and their messages are deleted this many days after the last message, unless they became a lead.
    'retention_days' => 90,

];
