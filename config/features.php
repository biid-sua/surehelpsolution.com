<?php

/*
|--------------------------------------------------------------------------
| Paid features (spec §30–31, FND-18, docs/decisions.md D46)
|--------------------------------------------------------------------------
|
| Features a plan or an add-on can include. Each one is open to every business, only to plans and
| add-ons that list its key, only to the businesses staff pick (hidden from the rest, D107), or
| switched off for everyone (hidden, D119), set under Admin › Billing › Feature access. Staff can
| also switch a feature on or off for one business. Everything starts open to everyone, so turning
| this on never takes anything away by surprise, except a feature with a 'default_mode': 'plan' for
| a new paid service that costs us money to run (D57), 'selected' for a careful roll-out, or 'off'
| for a feature that ships switched off, to be switched on (and announced as new) later. 'audience'
| says who gets an 'off' feature once it's switched on. 'group' sorts the admin list, and
| 'route' is where a business finds it (linked from "New in SureHelp").
|
*/

return [

    'calendar_sync' => [
        'group' => 'Calendar & connected apps',
        'route' => 'app.business.calendars',
        'label' => 'Google / Outlook calendar sync',
        'description' => 'Connect Google Calendar or Outlook; bookings and busy times stay in sync.',
    ],
    'ai_assistant' => [
        'group' => 'Messages & AI',
        'route' => 'app.inbox.assistant',
        'label' => 'AI messaging assistant',
        'description' => 'Answers website chat and social messages, books and hands over.',
    ],
    'social_publishing' => [
        'group' => 'Marketing & website',
        'route' => 'app.social.index',
        'label' => 'Social publishing',
        'description' => 'Plan and publish posts to Facebook, Instagram, LinkedIn and Google Business Profile.',
    ],
    'website_builder' => [
        'group' => 'Marketing & website',
        'route' => 'app.site',
        'label' => 'Website builder',
        'description' => 'A website we host, made from a template and the business\'s details, changed by asking in plain words.',
    ],
    'website_tools' => [
        'group' => 'Marketing & website',
        'route' => 'app.website',
        'label' => 'Website tools',
        'description' => 'Website health and SEO check, plus booking, click-to-call and the contact form in the snippet.',
    ],
    'customer_emails' => [
        'group' => 'Customers & sales',
        'route' => 'app.business.emails',
        'label' => 'Appointment emails to customers',
        'description' => 'Confirmations, reminders, changes and cancellations sent in the business\'s name.',
    ],

    'seo_service' => [
        'group' => 'Marketing & website',
        'route' => 'app.seo',
        'label' => 'SEO service',
        'description' => 'AI-assisted SEO work every month (keyword plan, page titles and descriptions, local pages, FAQ), with Search Console statistics.',
        'default_mode' => 'plan',
    ],
    'reviews' => [
        'group' => 'Marketing & website',
        'route' => 'app.reviews',
        'label' => 'Reviews',
        'description' => 'Google reviews in one place, with alerts, AI reply drafts and replies posted from SureHelp.',
    ],
    'customer_payments' => [
        'group' => 'Customers & sales',
        'route' => 'app.payments',
        'label' => 'Deposits and payments',
        'description' => 'Take booking deposits and send pay-by-text links, paid into the business\'s own Stripe account.',
        'default_mode' => 'plan',
    ],
    'value_report' => [
        'group' => 'Reports & branding',
        'route' => 'app.results',
        'label' => 'Dollars report',
        'description' => 'What SureHelp was worth each month in dollars: jobs booked, what the business paid us and the return, on Results, the dashboard and the monthly email.',
    ],
    'lead_response' => [
        'group' => 'Customers & sales',
        'route' => 'app.leads',
        'label' => 'Instant replies to new leads',
        'description' => 'New leads from the website form, Facebook, Google, Angi, Thumbtack or Yelp get a text and an email within seconds, and the owner\'s phone rings to connect them.',
    ],
    'quickbooks' => [
        'group' => 'Calendar & connected apps',
        'route' => 'app.business.apps',
        'label' => 'QuickBooks Online',
        'description' => 'Payments taken through SureHelp go into QuickBooks, and QuickBooks customers come into SureHelp so receptionists know them.',
    ],
    'jobber' => [
        'group' => 'Calendar & connected apps',
        'route' => 'app.business.apps',
        'label' => 'Jobber',
        'description' => 'Bookings SureHelp makes go into Jobber as new requests, and Jobber clients come into SureHelp so receptionists know them.',
    ],
    'housecall_pro' => [
        'group' => 'Calendar & connected apps',
        'route' => 'app.business.apps',
        'label' => 'Housecall Pro',
        'description' => 'Bookings SureHelp makes go onto the Housecall Pro schedule as jobs, and Housecall Pro customers come into SureHelp so receptionists know them.',
    ],
    'call_tracking' => [
        'group' => 'Calls & phone',
        'route' => 'app.call-tracking',
        'label' => 'Call tracking',
        'description' => 'A separate SureHelp number for each ad or marketing channel, with calls, booked jobs and cost per job for each one.',
    ],
    'estimates' => [
        'group' => 'Customers & sales',
        'route' => 'app.documents.index',
        'label' => 'Estimates and invoices',
        'description' => 'Estimates customers accept online, turned into invoices they pay by card (with Deposits and payments) or that are marked paid.',
    ],
    'campaigns' => [
        'group' => 'Customers & sales',
        'route' => 'app.outreach',
        'label' => 'Reminders & win-back',
        'description' => 'Service reminders some months after a job, and campaigns to past customers by text and email, with who came back.',
    ],
    'automations' => [
        'group' => 'Customers & sales',
        'route' => 'app.business.automations',
        'label' => 'Automations',
        'description' => 'Texts, emails and tasks that run by themselves after calls, bookings and new customers, with ready-made recipes.',
    ],
    'ai_receptionist' => [
        'group' => 'Calls & phone',
        'route' => 'app.ai-receptionist',
        'label' => 'AI receptionist',
        'description' => 'An AI voice answers calls nobody can take (or after hours, or every call): it answers questions, books jobs, takes details and hands urgent calls to the team.',
        // Every call it answers costs AI and phone minutes: only for the businesses staff pick at first.
        'default_mode' => 'selected',
    ],
    'white_label' => [
        'group' => 'Reports & branding',
        'route' => 'app.business.branding',
        'label' => 'White-label',
        'description' => 'The business\'s own logo, colour and sender name on customer emails, booking pages and its portal, without SureHelp branding.',
        'default_mode' => 'plan',
    ],

];
