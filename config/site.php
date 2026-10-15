<?php

/*
| Search and sharing details for the public site (D58): the business facts used in structured data,
| a search title and description for each page, and the FAQs shown on the main service pages.
| Titles stay under about 60 characters and descriptions between 120 and 160, so Google shows
| them in full. Describe only what SureHelp actually does: the FAQs are shown on the page and
| repeated in the page's structured data, so the two always match.
*/

return [

    'name' => 'SureHelp Solutions',
    'alternate_name' => 'SureHelp',
    'phone' => '+1-858-321-3947',
    'same_as' => [
        'https://www.linkedin.com/company/surehelpsolution/',
        'https://www.facebook.com/surehelpsolution',
        'https://x.com/SureHelpSol',
        'https://www.instagram.com/surehelpsolution/',
    ],
    'x_handle' => '@SureHelpSol',
    'share_image' => 'assets/img/share.png',   // 1200 × 630, shown when a page is shared

    // Search titles and descriptions --------------------------------------------------------
    'meta' => [
        'home' => [
            'title' => '24/7 Live Answering Service for Small Business | SureHelp',
            'description' => 'Live receptionists answer your calls 24/7 in your business name, book appointments into your calendar and send every message instantly. Live in 48 hours.',
        ],
        'faq' => [
            'title' => 'Answering Service FAQ: Pricing, Setup & Booking | SureHelp',
            'description' => 'Answers about SureHelp\'s live answering service: how calls are handled, appointment booking, calendar sync, pricing, setup time, security and support.',
        ],

        'call-answering' => [
            'title' => 'Live Call Answering Service, 24/7 Receptionists | SureHelp',
            'description' => 'Trained receptionists answer every call in your business name, day and night. They take messages, book appointments and escalate urgent calls your way.',
        ],
        'appointment-booking' => [
            'title' => 'Appointment Booking & Scheduling Service | SureHelp',
            'description' => 'Callers book, move and cancel appointments by phone or online, around your real hours. Two-way sync with Google Calendar and Outlook stops double bookings.',
        ],
        'customer-management' => [
            'title' => 'Simple CRM That Fills Itself From Every Call | SureHelp',
            'description' => 'Every call, message and booking lands on the right customer\'s timeline automatically, with tasks, callbacks and escalations so nothing slips through.',
        ],
        'analytics-dashboard' => [
            'title' => 'Call Analytics: Calls, Leads & Bookings Reports | SureHelp',
            'description' => 'See calls answered, new leads, bookings and the revenue they bring in, by day, week and month. Download monthly reports as PDF or CSV.',
        ],
        'integrations' => [
            'title' => 'Integrations: Google Calendar, Outlook & API | SureHelp',
            'description' => 'Connect SureHelp to Google Calendar, Microsoft 365, your website, Facebook, Instagram and your own systems through our REST API.',
        ],
        'surehelp-answer' => [
            'title' => 'SureHelp Answer: Smart Call Routing & Escalation',
            'description' => 'Route every call to the right answer or the right person. Your rules decide what is urgent, who is told, and how quickly each call is followed up.',
        ],
        'surehelp-schedule' => [
            'title' => 'SureHelp Schedule: Online & Phone Booking in One Calendar',
            'description' => 'Take bookings by phone, on your website and in your calendar, all from the same availability, with reminders and self-service rescheduling.',
        ],
        'surehelp-intelligence' => [
            'title' => 'SureHelp Intelligence: AI Assistant for Your Business',
            'description' => 'An AI assistant that knows your services, hours and policies answers website chat and messages, books appointments and hands over to your team.',
        ],

        'small-business' => [
            'title' => 'Answering Service for Small Business, 24/7 | SureHelp',
            'description' => 'A professional front desk without hiring one. Live receptionists answer, book and take messages for teams of 1 to 10, with no setup fees.',
        ],
        'growing-business' => [
            'title' => 'Virtual Receptionist for Growing Teams | SureHelp',
            'description' => 'Scale customer service without adding headcount. Shared customer records, tasks, escalations and reports for teams of 11 to 50.',
        ],
        'enterprise' => [
            'title' => 'Enterprise Call Center & Answering Service | SureHelp',
            'description' => 'Dedicated agents, custom call volumes and SLAs, bilingual answering, integrations and compliance for multi-location organisations and high volumes.',
        ],
        'health-personal-care' => [
            'title' => 'Medical & Dental Answering Service, Clinics & Spas | SureHelp',
            'description' => 'Friendly receptionists book patients and clients, answer questions and handle after-hours calls for clinics, dental practices, salons and spas. BAA available.',
        ],
        'home-property-services' => [
            'title' => 'Answering Service for Plumbers, HVAC & Electricians | SureHelp',
            'description' => 'Never lose a job to voicemail. We answer service calls, book visits and estimates, and escalate emergencies like leaks or no heat to you straight away.',
        ],
        'specialty-trades' => [
            'title' => 'Answering Service for Contractors & Builders | SureHelp',
            'description' => 'While you are on site, we answer project enquiries, capture scope, budget and timing, and book estimates and site visits into your calendar.',
        ],
        'legal-financial' => [
            'title' => 'Legal Answering Service & Client Intake for Firms | SureHelp',
            'description' => 'Professional intake for law firms, accountants and financial advisers: new client calls answered, consultations booked, urgent matters escalated.',
        ],
        'education-coaching' => [
            'title' => 'Answering Service for Tutors, Coaches & Schools | SureHelp',
            'description' => 'Answer every enrolment enquiry, book sessions and consultations, and handle reschedules for tutors, coaches, trainers and education providers.',
        ],
        'retail-ecommerce' => [
            'title' => 'Customer Support for Shops & Online Stores | SureHelp',
            'description' => 'Friendly answers about products, orders, delivery and returns by phone, website chat, Messenger and Instagram, following your store\'s policies.',
        ],
        'event-venue' => [
            'title' => 'Answering Service for Event Venues & Planners | SureHelp',
            'description' => 'Answer event enquiries fast, capture date, guest numbers and budget, and book viewings and consultations for venues, planners and hospitality.',
        ],
        'nonprofits-ministries' => [
            'title' => 'Church & Nonprofit Answering Service | SureHelp',
            'description' => 'A warm, reliable answer for supporters, volunteers and people seeking help, for charities, churches and community organisations of any size.',
        ],
        'franchise-businesses' => [
            'title' => 'Franchise & Multi-Location Answering Service | SureHelp',
            'description' => 'The same brand experience at every location: each location gets its own hours, services and calendar, with results for each one and for the brand.',
        ],

        'success-stories' => [
            'title' => 'Answering Service Success Stories | SureHelp',
            'description' => 'How businesses use SureHelp to answer every call, what we measure for every customer, and how to try it risk-free with a 30-day money-back guarantee.',
        ],
        'roi-calculator' => [
            'title' => 'Missed Call Cost Calculator: Answering Service ROI | SureHelp',
            'description' => 'Work out what missed calls cost your business each month, and what answering them could bring in, with our free missed-call ROI calculator.',
        ],
        'implementation-guide' => [
            'title' => 'Answering Service Setup Guide: Live in 48 Hours | SureHelp',
            'description' => 'From sign-up to answered calls in 48 hours: your script, hours, services, calendar and call forwarding, step by step, with best practices.',
        ],
        'help-center' => [
            'title' => 'Help Center: Guides & Support | SureHelp Solutions',
            'description' => 'Guides and answers for SureHelp customers: your client portal, calls, appointments, calendar sync, billing, and how to reach our support team.',
        ],
        'webinars-training' => [
            'title' => 'Webinars & Training for SureHelp Customers',
            'description' => 'Training sessions and guides that help your team get the most from SureHelp: scripts, bookings, follow-ups, reports and automations.',
        ],
        'api-documentation' => [
            'title' => 'SureHelp REST API Documentation for Developers',
            'description' => 'Build on SureHelp with our REST API: calls, customers, appointments, tasks and more, with token authentication, rate limits and clear error codes.',
        ],
        'about' => [
            'title' => 'About SureHelp Solutions: Live Answering Service',
            'description' => 'SureHelp Solutions is a live answering service and front-desk platform built so small businesses never miss a customer call again.',
        ],
        'careers' => [
            'title' => 'Careers at SureHelp: Receptionist & Support Jobs',
            'description' => 'Join a team of receptionists, supervisors and builders who make sure no customer call goes unanswered. Tell us about yourself.',
        ],
        'press' => [
            'title' => 'Press & Media | SureHelp Solutions',
            'description' => 'News, company information, brand assets and media contacts for SureHelp Solutions, the live answering service for small businesses.',
        ],
        'partners' => [
            'title' => 'Partner Program for Agencies & Software | SureHelp',
            'description' => 'Agencies, consultants, software providers and associations: offer your clients a live answering service that is set up in 48 hours.',
        ],

        'terms-of-use' => ['title' => 'Terms of Use | SureHelp Solutions'],
        'privacy-policy' => ['title' => 'Privacy Policy | SureHelp Solutions'],
        'data-processing-addendum' => ['title' => 'Data Processing Addendum | SureHelp Solutions'],
        'business-associate-agreement' => ['title' => 'Business Associate Agreement (BAA) | SureHelp Solutions'],
        'data-security' => ['title' => 'Data Security Statement | SureHelp Solutions'],
        'cookie-notice' => ['title' => 'Cookie Notice | SureHelp Solutions'],
    ],

    // FAQs on the main service pages (shown on the page and in its structured data) --------------
    'faqs' => [
        'call-answering' => [
            ['How does a live answering service work?', 'You forward your business number to SureHelp all the time, after hours, or when you are busy. A trained receptionist answers in your business name, follows the script you approve, books appointments or takes a message, and the call appears in your client portal within seconds.'],
            ['Do I need a new phone number or phone system?', 'No. You keep your existing number and simply forward it to SureHelp. You can switch forwarding off again whenever you like.'],
            ['What happens with urgent calls?', 'You decide what counts as urgent. Those calls follow your escalation rules and reach you or the person you name straight away; everything else is booked, answered or logged for a callback.'],
            ['How quickly can you start answering my calls?', 'Most businesses are live within 48 hours. We set up your greeting, script, services, hours and calendar with you before the first call.'],
        ],
        'appointment-booking' => [
            ['Which calendars does SureHelp work with?', 'Google Calendar and Microsoft 365 / Outlook, with two-way sync: busy times in your calendar block bookings, and new bookings appear in your calendar.'],
            ['How do you avoid double bookings?', 'Receptionists only offer times your hours, holidays, service lengths and calendar allow, and availability is checked again at the moment the booking is made.'],
            ['Can customers change or cancel their own appointments?', 'Yes. Each confirmation includes a secure link to move or cancel the appointment, within the notice period you set.'],
            ['Can I approve bookings before they are confirmed?', 'Yes. You can choose to approve each booking our receptionists make before the customer is told it is confirmed.'],
        ],
        'small-business' => [
            ['Is an answering service worth it for a small business?', 'If you miss calls while you are on a job or with a customer, each one can be a lost sale. An answering service costs less than hiring a receptionist and answers every call, including evenings and weekends.'],
            ['Are there setup fees or long contracts?', 'No setup fees, and you can cancel any time. Plans are monthly, include a set number of minutes with nights and weekends, and come with a 30-day money-back guarantee.'],
            ['Will callers know you are not in my office?', 'Calls are answered in your business name with your greeting, script and FAQs, so callers get the same answers you would give.'],
            ['How do I see my messages?', 'Every call is summarised in your client portal and the SureHelp app within seconds, and can be emailed to you too.'],
        ],
        'health-personal-care' => [
            ['Do you sign a Business Associate Agreement (BAA)?', 'Yes. For healthcare practices we sign a Business Associate Agreement before we handle patient calls.'],
            ['Can you book patients into our schedule?', 'Yes. Receptionists book, move and cancel appointments around your real hours and service lengths, with Google Calendar or Microsoft 365 sync.'],
            ['How are after-hours and urgent calls handled?', 'You tell us how to handle urgent symptoms or concerns, and those calls follow your escalation rules. Everything else is booked or passed on as a message.'],
        ],
        'home-property-services' => [
            ['Can you answer emergency calls at night and on weekends?', 'Yes. We answer 24/7, and calls you define as emergencies, such as leaks, no heat or power problems, are escalated to you straight away.'],
            ['Can you book estimates and service visits?', 'Yes. Visits and estimates are booked into your calendar around your hours, with travel buffers between jobs.'],
            ['What details do you collect from callers?', 'Name, phone, address, the problem and how urgent it is, plus any questions from your script, so you arrive prepared.'],
        ],
        'legal-financial' => [
            ['Do your receptionists give legal or financial advice?', 'No. They follow your intake questions, book consultations and take messages, and never give legal or financial advice.'],
            ['Is client information kept confidential?', 'Yes. Confidential details go only to your team, through your secure client portal.'],
            ['Can you book consultations for new clients?', 'Yes. New client calls are taken through your intake questions and booked into your calendar.'],
        ],
    ],

    // The FAQ page (/faq): shown on the page and in its structured data ---------------------------
    'faq_page' => [
        ['fa-question-circle', 'General questions', [
            ['What services does SureHelp Solutions provide?', 'SureHelp Solutions provides professional call answering, appointment booking, customer management and calendar integrations for businesses of all sizes. We answer 24/7, so you never miss an important call.'],
            ['How quickly can I get started?', 'We can have your business up and running within 48 hours of signing up. Our onboarding process is streamlined to get you connected quickly.'],
            ['What industries do you serve?', 'We serve a wide range of industries, including healthcare, home services, trades, legal and financial services, education, retail, events, nonprofits and franchises. Your script and rules are tailored to your business.'],
        ]],
        ['fa-phone', 'Call answering', [
            ['How does the call answering service work?', 'Our professional receptionists answer your calls 24/7 in your business name. They take detailed messages, answer questions from your script, book appointments and escalate urgent calls to you when needed.'],
            ['Can I customize how calls are handled?', 'Yes. You choose your greeting, call script, FAQs, business hours, escalation rules and specific instructions for different types of calls.'],
            ['How are messages delivered to me?', 'Every call is summarised in your secure client portal and the SureHelp app within seconds, and can be emailed to you too. You choose who on your team is told about what.'],
        ]],
        ['fa-calendar-check', 'Appointment booking', [
            ['Can you book appointments for my business?', 'Yes. Our receptionists book appointments directly into your calendar, around your real hours, holidays and service lengths.'],
            ['What calendar systems do you support?', 'Google Calendar and Microsoft 365 / Outlook, with two-way sync: busy times in your calendar block bookings, and new bookings appear in your calendar.'],
        ]],
        ['fa-dollar-sign', 'Pricing and billing', [
            ['How is pricing structured?', 'Our pricing is based on the number of calls handled and the services you choose. We offer flexible plans, from call answering to a complete front-desk solution.'],
            ['Are there any setup fees?', 'No, there are no setup fees. You only pay for the services you use on a monthly basis.'],
            ['Can I change my plan at any time?', 'Yes, you can upgrade or downgrade your plan at any time. Changes take effect on your next billing cycle.'],
        ]],
        ['fa-shield-alt', 'Security and privacy', [
            ['How do you protect my customer data?', 'We implement enterprise-grade security measures including encryption, secure data centers, access controls, and regular security audits to protect your customer information.'],
            ['Are you HIPAA compliant?', 'Yes, we are HIPAA compliant and can sign Business Associate Agreements (BAA) with healthcare providers.'],
            ['Where is my data stored?', 'Your data is stored in secure, SOC 2 compliant data centers with redundant backups and disaster recovery procedures.'],
        ]],
        ['fa-headset', 'Support and training', [
            ['What kind of support do you provide?', 'We provide 24/7 customer support via phone, email, and chat. Our dedicated account managers are available to help with any questions or concerns.'],
            ['Do you provide training for my team?', 'Yes, we provide comprehensive training for your team on how to use our services effectively and get the most value from our platform.'],
            ['How do I access my account and reports?', 'You can access your account, view reports, and manage settings through our secure client portal, available 24/7.'],
        ]],
    ],

];
