<?php

/*
| The public website's content (D55). One place for the words, prices and claims shown to
| visitors, so templates stay design-only. Claims must be ones SureHelp can stand behind:
| no invented customers, numbers, reviews or certifications (FTC rules on endorsements and
| deceptive claims). Real testimonials can be added to 'testimonials' with written permission.
*/

return [

    'phone' => '+1 (858) 321-3947',
    'phone_href' => '+18583213947',

    'social' => [
        'facebook' => 'https://www.facebook.com/surehelpsolution',
        'instagram' => 'https://www.instagram.com/surehelpsolution/',
        'linkedin' => 'https://www.linkedin.com/company/surehelpsolution/',
        'x' => 'https://x.com/SureHelpSol',
    ],

    // Shown as commitments, not as results achieved for other customers.
    'promises' => [
        ['value' => '24/7', 'label' => 'Live answering, nights, weekends and holidays included'],
        ['value' => '48 h', 'label' => 'From sign-up to your first answered call'],
        ['value' => '$0', 'label' => 'Setup fees. Cancel any time'],
        ['value' => '30 days', 'label' => 'Money-back guarantee on your first month'],
    ],

    'services' => [
        'call-answering' => [
            'nav' => '24/7 call answering',
            'icon' => 'phone',
            'title' => 'Live call answering, around the clock',
            'summary' => 'Real people answer in your business name, follow your script and send you every detail.',
            'intro' => 'Your phone rings, a SureHelp receptionist answers with your greeting, knows your services, prices and rules, and handles the call the way you would: takes the message, books the visit or puts urgent calls straight through to you.',
            'points' => [
                ['Your greeting, your script', 'We answer as your business. Scripts, FAQs and call rules come from your portal, so every agent gives the same answers you would.'],
                ['A briefing on every call', 'Agents see your hours, services, pricing guidance and what counts as urgent before they say hello.'],
                ['Urgent calls escalated', 'Emergencies follow your rules: the call is marked urgent, you\'re alerted straight away and it\'s tracked until someone has it.'],
                ['Every call in your portal', 'Caller, reason, outcome and notes arrive in seconds, by email or in the app, with the customer record updated.'],
                ['Follow-ups that happen', 'Callers who need a call back become tasks with a due time, so nobody falls through the cracks.'],
                ['Quality you can see', 'Supervisors review calls and coach agents. You see outcomes and trends in your results dashboard.'],
            ],
        ],
        'appointment-scheduling' => [
            'nav' => 'Appointment scheduling',
            'icon' => 'calendar',
            'title' => 'Bookings straight into your calendar',
            'summary' => 'We book, move and cancel appointments around your real availability, then remind your customers.',
            'intro' => 'Agents only offer times you can actually do: your hours, holidays, service lengths and travel buffers, and the busy times in your own Google or Microsoft calendar. The booking lands in your calendar before the caller hangs up.',
            'points' => [
                ['Real availability', 'Business hours, holidays, service durations and buffers decide which times are offered. No double bookings.'],
                ['Google and Microsoft sync', 'Connect your calendar: your busy times block bookings, and our bookings appear in it.'],
                ['Reminders that cut no-shows', 'Customers get a confirmation and a reminder by email before every visit.'],
                ['Self-service changes', 'Customers can move or cancel from a secure link, within the notice period you set.'],
                ['Your approval if you want it', 'Have agent bookings wait for your OK, or let them confirm straight away.'],
                ['Bookings from your website', 'Add online booking to your own site, using the same availability.'],
            ],
        ],
        'ai-receptionist' => [
            'nav' => 'AI receptionist & inbox',
            'icon' => 'chat',
            'title' => 'One inbox, with an AI assistant that knows your business',
            'summary' => 'Website chat, Facebook Messenger and Instagram messages in one place, answered in minutes.',
            'intro' => 'Messages from your website, Facebook and Instagram arrive in one inbox. Our AI assistant answers from your own business information, checks real availability, books appointments and hands over to a person whenever it should. You decide how much it does.',
            'points' => [
                ['Every channel, one inbox', 'Website chat, Messenger and Instagram DMs, with the customer\'s history and next appointment beside each conversation.'],
                ['Answers from your information', 'The assistant only uses your services, hours, prices and FAQs. If it isn\'t sure, it says so and hands over.'],
                ['Books like a receptionist', 'It checks availability, books under your rules, saves customer details and creates follow-ups.'],
                ['You stay in control', 'Off, suggest-only or fully automatic, per channel. It steps back the moment a person replies.'],
                ['It learns from you', 'Rate replies and correct them. Approved corrections become guidelines it follows.'],
                ['Platform rules respected', 'Meta\'s messaging windows are enforced automatically, so your pages stay in good standing.'],
            ],
        ],
        'customer-management' => [
            'nav' => 'Customers & follow-ups',
            'icon' => 'users',
            'title' => 'Every caller becomes a customer record',
            'summary' => 'A simple CRM that fills itself from calls, chats and bookings, with tasks, automations and results.',
            'intro' => 'Each call, message and booking updates the customer\'s timeline automatically. Callbacks and escalations become tasks with owners and due times, and your results page shows what all of it is worth.',
            'points' => [
                ['Customer timeline', 'Calls, messages, appointments and notes on one page, matched by phone and email.'],
                ['Tasks and escalations', 'Callbacks, quotes and urgent issues are assigned, tracked and reminded until they\'re done.'],
                ['Automations', 'Send a thank-you, a review request or a follow-up task automatically when something happens.'],
                ['Results you can measure', 'Calls answered, leads, bookings and estimated revenue, every day and in a monthly report.'],
                ['Import in minutes', 'Bring your existing customer list from a spreadsheet, with duplicates handled for you.'],
                ['On your phone', 'Everything in the SureHelp app and API: calls, customers, tasks and approvals.'],
            ],
        ],
        'marketing-tools' => [
            'nav' => 'Social & website tools',
            'icon' => 'megaphone',
            'title' => 'Grow beyond the phone',
            'summary' => 'Schedule social posts, add chat and booking to your website, and check its health.',
            'intro' => 'Write once and publish to Facebook, Instagram, LinkedIn and your Google Business Profile, now or months ahead. Add SureHelp chat, booking and a contact form to your own website, and get a plain-English report on what to fix.',
            'points' => [
                ['Social scheduling', 'Plan posts in a calendar, publish now or schedule up to a year ahead, with previews for each network.'],
                ['Approvals', 'Posts written by your team or ours wait for the owner\'s OK.'],
                ['Website chat and booking', 'One snippet adds chat, online booking and a contact form to the site you already have.'],
                ['Website health check', 'Monthly checks for speed, mobile, SEO basics and broken links, explained simply.'],
                ['Leads go to the right place', 'Website enquiries become customers and tasks in your portal, not emails that get lost.'],
                ['Consent handled properly', 'Text-message consent is optional and recorded as given, so you stay compliant.'],
            ],
        ],
    ],

    // Marketing copy per industry; the services, urgent-call rules and FAQs come from config/industries.php.
    'industries' => [
        'plumbing' => ['headline' => 'Answering for plumbers', 'intro' => 'Burst pipes don\'t wait for business hours. We answer every call, book the job and get true emergencies to you fast.'],
        'hvac' => ['headline' => 'Answering for heating & cooling', 'intro' => 'Peak season floods your phones. We book tune-ups and repairs, and flag no-heat and no-cooling calls the moment they come in.'],
        'electrical' => ['headline' => 'Answering for electricians', 'intro' => 'Stay on the tools. We take the details, book the visit and escalate anything that sounds dangerous.'],
        'cleaning' => ['headline' => 'Answering for cleaning companies', 'intro' => 'Quotes, recurring bookings and changes handled politely, with every detail in your portal.'],
        'dental' => ['headline' => 'Answering for dental practices', 'intro' => 'New-patient calls answered, appointments booked and urgent pain cases routed by your rules, after hours too.'],
        'salon' => ['headline' => 'Answering for salons & spas', 'intro' => 'Your stylists keep working while we fill the book, move appointments and take messages.'],
        'legal' => ['headline' => 'Answering for law firms', 'intro' => 'Every potential client gets a professional first impression and a consultation booked, with intake details captured.'],
        'auto' => ['headline' => 'Answering for auto repair shops', 'intro' => 'We book inspections and repairs, answer the common questions and keep your bays full.'],
    ],

    // Monthly prices in US dollars. Annual billing saves 10%.
    'plans' => [
        [
            'name' => 'Essential',
            'price' => 300,
            'for' => 'Solo professionals and coaches',
            'minutes' => '100 minutes a month',
            'overage' => '$2.00 per extra call',
            'features' => ['24/7 call answering', 'Appointment scheduling', 'Email and app notifications', 'Client portal and app access', 'Message taking and forwarding', 'Basic follow-up on up to 50 calls a month'],
        ],
        [
            'name' => 'Professional',
            'price' => 400,
            'for' => 'Home services and growing businesses',
            'minutes' => '150 minutes a month',
            'overage' => '$1.50 per extra call',
            'features' => ['Everything in Essential', 'Custom call scripts', 'Full follow-up on every call', 'Monthly performance reports', 'Priority notifications', 'Enhanced client portal and analytics'],
        ],
        [
            'name' => 'Growth',
            'price' => 450,
            'for' => 'Medical, legal and financial practices',
            'minutes' => '200 minutes a month',
            'overage' => '$1.25 per extra call',
            'features' => ['Everything in Professional', 'Dedicated account manager', 'Advanced analytics and reporting', 'Priority support and white-label options', 'Twice the call follow-up, with priority routing'],
            'featured' => true,
        ],
        [
            'name' => 'Enterprise',
            'price' => null,
            'for' => 'Franchises and multi-location businesses',
            'minutes' => 'Custom minutes',
            'overage' => 'Volume pricing',
            'features' => ['Everything in Growth', 'Dedicated franchise teams', 'Custom integrations and automation', 'White-glove onboarding', 'Guaranteed service levels and priority escalation', 'Bilingual support'],
        ],
    ],

    'faqs' => [
        'Getting started' => [
            ['How quickly can we go live?', 'Usually within 48 hours. We set up your greeting, script and call rules with you, run test calls, then you forward your line to us whenever you\'re ready.'],
            ['Do I need new phone numbers or equipment?', 'No. You forward your existing number to SureHelp, all the time or only when you\'re busy or closed. You can switch it back in seconds.'],
            ['Is there a contract?', 'No long-term contract. Plans are monthly (or annual with a 10% saving) and you can cancel any time. Your first month has a 30-day money-back guarantee.'],
            ['Are there setup fees?', 'No. You pay for your plan, plus any calls beyond what it includes.'],
        ],
        'How calls are handled' => [
            ['Who answers my calls?', 'Trained SureHelp receptionists who are assigned to your business and briefed on it. They answer in your business name, following your script and rules.'],
            ['What happens with urgent calls?', 'You decide what counts as urgent. Those calls are escalated to you straight away and tracked until someone has taken them.'],
            ['How do I get my messages?', 'Instantly in your client portal and the SureHelp app, and by email if you like. Each call shows the caller, reason, outcome and notes.'],
            ['Can you book appointments into my calendar?', 'Yes. We book around your real availability, and with Google or Microsoft calendar sync the bookings appear in your own calendar.'],
        ],
        'Pricing and billing' => [
            ['What counts towards my plan?', 'Each call our team handles for you counts towards the minutes your plan includes. Your portal shows calls and minutes used so far this month.'],
            ['Can I change plans?', 'Yes, up or down at any time. Changes apply from your next billing period.'],
            ['How do I pay?', 'You receive a monthly invoice with a secure payment link. Annual plans are invoiced once a year.'],
        ],
        'Privacy and security' => [
            ['Is my customers\' information safe?', 'Data is encrypted in transit, access is limited to the people who serve your business, our staff sign in with two-step verification, and access is logged. Read our Data Security page for details.'],
            ['Can you handle healthcare calls?', 'Yes. For practices that need it we sign a Business Associate Agreement (BAA) and follow its rules.'],
            ['Do you text my customers?', 'Only when they agree to it, and they can reply STOP at any time. Consent is recorded as given.'],
        ],
    ],

    // Real customer quotes only, with their written permission: ['quote' => '', 'name' => '', 'business' => '', 'city' => ''].
    'testimonials' => [],

];
