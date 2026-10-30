<?php

/*
|--------------------------------------------------------------------------
| Paid features (spec §30–31, FND-18, docs/decisions.md D46)
|--------------------------------------------------------------------------
|
| Features a plan or an add-on can include. Each one is open to every business, only to plans and
| add-ons that list its key, or only to the businesses staff pick (hidden from the rest, D107), set
| under Admin › Billing › Feature access. Staff can also switch a feature on or off for one business. Everything starts open to everyone, so turning this on never
| takes anything away by surprise, except a feature with 'default_mode' => 'plan': a new paid service
| that costs us money to run (D57) starts closed until a plan or add-on includes it.
|
*/

return [

    'calendar_sync' => [
        'label' => 'Google / Outlook calendar sync',
        'description' => 'Connect Google Calendar or Outlook; bookings and busy times stay in sync.',
    ],
    'ai_assistant' => [
        'label' => 'AI messaging assistant',
        'description' => 'Answers website chat and social messages, books and hands over.',
    ],
    'social_publishing' => [
        'label' => 'Social publishing',
        'description' => 'Plan and publish posts to Facebook, Instagram, LinkedIn and Google Business Profile.',
    ],
    'website_builder' => [
        'label' => 'Website builder',
        'description' => 'A website we host, made from a template and the business\'s details, changed by asking in plain words.',
    ],
    'website_tools' => [
        'label' => 'Website tools',
        'description' => 'Website health and SEO check, plus booking, click-to-call and the contact form in the snippet.',
    ],
    'customer_emails' => [
        'label' => 'Appointment emails to customers',
        'description' => 'Confirmations, reminders, changes and cancellations sent in the business\'s name.',
    ],

    'seo_service' => [
        'label' => 'SEO service',
        'description' => 'AI-assisted SEO work every month (keyword plan, page titles and descriptions, local pages, FAQ), with Search Console statistics.',
        'default_mode' => 'plan',
    ],
    'reviews' => [
        'label' => 'Reviews',
        'description' => 'Google reviews in one place, with alerts, AI reply drafts and replies posted from SureHelp.',
    ],
    'customer_payments' => [
        'label' => 'Deposits and payments',
        'description' => 'Take booking deposits and send pay-by-text links, paid into the business\'s own Stripe account.',
        'default_mode' => 'plan',
    ],
    'value_report' => [
        'label' => 'Dollars report',
        'description' => 'What SureHelp was worth each month in dollars: jobs booked, what the business paid us and the return, on Results, the dashboard and the monthly email.',
    ],
    'lead_response' => [
        'label' => 'Instant replies to new leads',
        'description' => 'New leads from the website form, Facebook, Google, Angi, Thumbtack or Yelp get a text and an email within seconds, and the owner\'s phone rings to connect them.',
    ],
    'ai_receptionist' => [
        'label' => 'AI receptionist',
        'description' => 'An AI voice answers calls nobody can take (or after hours, or every call): it answers questions, books jobs, takes details and hands urgent calls to the team.',
        // Every call it answers costs AI and phone minutes: only for the businesses staff pick at first.
        'default_mode' => 'selected',
    ],
    'white_label' => [
        'label' => 'White-label',
        'description' => 'The business\'s own logo, colour and sender name on customer emails, booking pages and its portal, without SureHelp branding.',
        'default_mode' => 'plan',
    ],

];
