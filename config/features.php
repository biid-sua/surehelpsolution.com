<?php

/*
|--------------------------------------------------------------------------
| Paid features (spec §30–31, FND-18, docs/decisions.md D46)
|--------------------------------------------------------------------------
|
| Features a plan or an add-on can include. Each one is either open to every business, or only to
| plans and add-ons that list its key (Admin › Billing › Feature access). Staff can also switch a
| feature on or off for one business. Everything starts open to everyone, so turning this on never
| takes anything away by surprise.
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
    'website_tools' => [
        'label' => 'Website tools',
        'description' => 'Website health and SEO check, plus booking, click-to-call and the contact form in the snippet.',
    ],
    'customer_emails' => [
        'label' => 'Appointment emails to customers',
        'description' => 'Confirmations, reminders, changes and cancellations sent in the business\'s name.',
    ],

];
