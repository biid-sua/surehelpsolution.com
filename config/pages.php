<?php

/*
| Content for the site's menu pages (products, solutions, resources, company), shown in the
| existing site design (layouts.app + legal-pages.css). Each section: icon (Font Awesome),
| heading, text (paragraphs), points (bullets), cards ([title, text]), highlight ([title, text]).
| Describe only what SureHelp actually does.
*/

$industry = fn (string $title, string $subtitle, string $intro, array $calls, array $handled, string $highlight) => [
    'group' => 'solutions',
    'title' => $title,
    'subtitle' => $subtitle,
    'sections' => [
        ['icon' => 'fa-bullseye', 'heading' => 'Why it matters', 'text' => [$intro]],
        ['icon' => 'fa-phone-alt', 'heading' => 'Calls we handle for you', 'points' => $calls],
        ['icon' => 'fa-cogs', 'heading' => 'How it works for your business', 'points' => $handled],
        ['icon' => 'fa-shield-alt', 'heading' => 'Handled your way', 'highlight' => ['Your rules, every call', $highlight]],
    ],
];

$common = [
    'Your own greeting, script and FAQs, so every caller hears the same answers you would give',
    'Appointments booked around your real hours, holidays and service lengths',
    'Google or Microsoft calendar sync, so bookings land in the calendar you already use',
    'Every call summarised in your client portal and app within seconds',
    'Urgent calls escalated to you straight away, using the rules you set',
];

return [

    // Products ---------------------------------------------------------------
    'call-answering' => [
        'group' => 'products',
        'title' => 'Call Answering Service',
        'subtitle' => '24/7 professional call support from live receptionists who answer in your business name',
        'sections' => [
            ['icon' => 'fa-headset', 'heading' => 'Real people, every call', 'text' => ['Your calls are answered day and night by trained SureHelp receptionists. They use your greeting, follow your script and know your services, prices and policies before they say hello, so your customers get the answer you would give.']],
            ['icon' => 'fa-list-check', 'heading' => 'What every call includes', 'cards' => [
                ['Your greeting and script', 'Calls are answered in your business name, with the script and FAQs you approve.'],
                ['Message taking', 'Caller details, reason and notes captured accurately and delivered instantly.'],
                ['Appointment booking', 'Visits booked around your real availability, straight into your calendar.'],
                ['Urgent call escalation', 'Emergencies follow your rules and reach you immediately.'],
                ['Follow-up tasks', 'Callers who need a call back become tasks with a due time.'],
                ['Quality reviews', 'Supervisors review calls and coach agents on your account.'],
            ]],
            ['icon' => 'fa-mobile-alt', 'heading' => 'Know about every call', 'text' => ['Each call appears in your client portal and the SureHelp app with the caller, reason, outcome and notes, and can be emailed to you too. Your results page shows calls answered, leads and bookings over time.']],
            ['icon' => 'fa-rocket', 'heading' => 'Live in 48 hours', 'highlight' => ['No new phone system', 'Keep your number. Forward it to SureHelp all the time, after hours, or when you are busy, and switch it back whenever you like.']],
        ],
    ],

    'appointment-booking' => [
        'group' => 'products',
        'title' => 'Appointment Booking',
        'subtitle' => 'A smart scheduling system that books, moves and cancels appointments around your real availability',
        'sections' => [
            ['icon' => 'fa-calendar-check', 'heading' => 'Bookings without the back-and-forth', 'text' => ['Our receptionists only offer times you can actually do. Your business hours, holidays, service durations and travel buffers decide what is available, and the booking is confirmed before the caller hangs up.']],
            ['icon' => 'fa-sync-alt', 'heading' => 'Works with your calendar', 'cards' => [
                ['Google Calendar', 'Busy times in your Google Calendar block bookings, and new bookings appear in it.'],
                ['Microsoft Outlook', 'The same two-way sync with Microsoft 365 and Outlook calendars.'],
                ['No double bookings', 'Availability is checked again at the moment of booking.'],
            ]],
            ['icon' => 'fa-bell', 'heading' => 'Fewer no-shows', 'points' => [
                'Confirmation and reminder emails sent to your customers automatically',
                'Customers can move or cancel from a secure link, within the notice period you set',
                'Optionally approve each booking our agents make before it is confirmed',
                'Online booking for your own website, using the same availability',
            ]],
        ],
    ],

    'customer-management' => [
        'group' => 'products',
        'title' => 'Customer Management',
        'subtitle' => 'A complete CRM that fills itself from every call, message and booking',
        'sections' => [
            ['icon' => 'fa-address-card', 'heading' => 'Every caller becomes a customer record', 'text' => ['Calls, messages, appointments and notes are matched to the right customer by phone or email and collected on one timeline, without anyone typing them in twice.']],
            ['icon' => 'fa-tasks', 'heading' => 'Nothing falls through the cracks', 'cards' => [
                ['Tasks and callbacks', 'Callbacks, quotes and follow-ups get an owner and a due time, with reminders.'],
                ['Escalations', 'Urgent issues are tracked until someone has acknowledged and resolved them.'],
                ['Automations', 'Send a thank-you, a review request or create a task automatically when something happens.'],
                ['Import your list', 'Bring existing customers in from a spreadsheet, with duplicates handled.'],
            ]],
            ['icon' => 'fa-user-shield', 'heading' => 'Your data, under your control', 'text' => ['Only your team and the agents serving your business can see your customers. Owners can export their data at any time and choose how long call history is kept.']],
        ],
    ],

    'analytics-dashboard' => [
        'group' => 'products',
        'title' => 'Analytics Dashboard',
        'subtitle' => 'Clear insight into your calls, leads, bookings and the revenue they bring in',
        'sections' => [
            ['icon' => 'fa-chart-line', 'heading' => 'Results you can measure', 'text' => ['Your dashboard shows what our team did for you today, this week or any range you choose: calls answered, new leads, appointments booked and an estimate of the revenue they represent, based on your average job value.']],
            ['icon' => 'fa-chart-pie', 'heading' => 'What you can see', 'points' => [
                'Calls by outcome: booked, message taken, escalated, follow-up needed',
                'New customers and returning callers',
                'Appointments booked and their outcomes',
                'Open tasks and overdue follow-ups',
                'Trends compared with the previous period',
            ]],
            ['icon' => 'fa-file-alt', 'heading' => 'Reports that come to you', 'highlight' => ['Monthly results report', 'On the first of each month you receive a summary of last month by email, with a PDF to keep or share. Results and appointments can also be downloaded as CSV.']],
        ],
    ],

    'integrations' => [
        'group' => 'products',
        'title' => 'Integration Directory',
        'subtitle' => 'Connect SureHelp to the tools you already use',
        'sections' => [
            ['icon' => 'fa-plug', 'heading' => 'Available integrations', 'cards' => [
                ['Google Calendar', 'Two-way availability and booking sync.'],
                ['Microsoft Outlook / 365', 'Two-way availability and booking sync.'],
                ['Facebook Pages', 'Messenger conversations in your inbox, and scheduled posts.'],
                ['Instagram', 'Direct messages in your inbox, and scheduled posts.'],
                ['LinkedIn', 'Scheduled posts for your company page.'],
                ['Google Business Profile', 'Scheduled updates, offers and events.'],
                ['Your website', 'Chat, online booking and a contact form, added with one snippet.'],
                ['Mobile app and API', 'Calls, customers, tasks and appointments for your own tools.'],
            ]],
            ['icon' => 'fa-shield-alt', 'heading' => 'Connected safely', 'text' => ['Connections use each provider\'s official sign-in. Access keys are encrypted and never shown in the browser, and you can disconnect any integration at any time from your portal.']],
            ['icon' => 'fa-lightbulb', 'heading' => 'Need something else?', 'highlight' => ['Tell us what you use', 'We add integrations based on what our customers ask for. Let us know which tool you\'d like SureHelp to work with.']],
        ],
    ],

    'surehelp-answer' => [
        'group' => 'products',
        'title' => 'SureHelp Answer',
        'subtitle' => 'Smart call routing: the right answer, the right person, every time',
        'sections' => [
            ['icon' => 'fa-random', 'heading' => 'Every call goes the right way', 'text' => ['SureHelp Answer decides what happens to each call based on your rules: book it, take a message, create a follow-up, or escalate it to you as urgent. Agents see your rules on screen while they talk, so the decision is consistent every time.']],
            ['icon' => 'fa-sliders-h', 'heading' => 'Rules you control', 'points' => [
                'Your own call outcomes, grouped into booked, message, follow-up and urgent',
                'What counts as an emergency for your business, and who to alert',
                'Business hours, holidays and closures, so callers hear the right thing',
                'Services you offer, with what to ask and what to say for each',
            ]],
            ['icon' => 'fa-bolt', 'heading' => 'Urgent means urgent', 'highlight' => ['Escalations that don\'t get lost', 'An urgent call becomes an escalation that stays open until someone on your team acknowledges and resolves it.']],
        ],
    ],

    'surehelp-schedule' => [
        'group' => 'products',
        'title' => 'SureHelp Schedule',
        'subtitle' => 'Automated booking by phone, on your website and in your calendar',
        'sections' => [
            ['icon' => 'fa-calendar-alt', 'heading' => 'One schedule, every channel', 'text' => ['Whether a customer calls, chats on your website or books online, SureHelp Schedule offers the same real availability and books straight into your calendar.']],
            ['icon' => 'fa-magic', 'heading' => 'Automated from start to finish', 'points' => [
                'Availability from your hours, holidays, service lengths and calendar busy times',
                'Instant confirmation emails and reminders before each visit',
                'Self-service changes and cancellations from a secure link',
                'Optional owner approval for bookings made by our agents',
            ]],
            ['icon' => 'fa-link', 'heading' => 'Learn more', 'text' => ['Calendar sync, reminders and self-service changes are explained in detail on the Appointment Booking page.']],
        ],
    ],

    'surehelp-intelligence' => [
        'group' => 'products',
        'title' => 'SureHelp Intelligence',
        'subtitle' => 'AI-powered insights and an AI assistant that knows your business',
        'sections' => [
            ['icon' => 'fa-brain', 'heading' => 'An AI assistant for your messages', 'text' => ['Website chats, Facebook Messenger and Instagram messages arrive in one inbox. The SureHelp AI assistant answers from your own business information, checks real availability, books appointments and hands over to a person whenever it should.']],
            ['icon' => 'fa-user-check', 'heading' => 'You stay in control', 'points' => [
                'Choose off, suggest-only or fully automatic, for each channel',
                'It only uses your services, hours, prices and FAQs',
                'It steps back as soon as a person replies',
                'Rate and correct its replies; approved corrections become guidelines it follows',
            ]],
            ['icon' => 'fa-chart-bar', 'heading' => 'Insights from every conversation', 'text' => ['Your dashboard and monthly report turn calls and messages into numbers you can act on: what customers ask for, how many become bookings, and what they are worth.']],
        ],
    ],

    // Solutions --------------------------------------------------------------
    'small-business' => [
        'group' => 'solutions',
        'title' => 'Small Business',
        'subtitle' => 'For teams of 1 to 10: a professional front desk without hiring one',
        'sections' => [
            ['icon' => 'fa-store', 'heading' => 'When you are the whole team', 'text' => ['You can\'t answer the phone while you\'re on a job, with a client or after hours. SureHelp answers every call in your name, books the work and sends you the details, so you never lose a customer to voicemail.']],
            ['icon' => 'fa-check-circle', 'heading' => 'Everything you need, nothing you don\'t', 'points' => $common],
            ['icon' => 'fa-piggy-bank', 'heading' => 'Simple pricing', 'highlight' => ['No setup fees, cancel any time', 'Monthly plans with a set number of minutes, nights and weekends included, and a 30-day money-back guarantee.']],
        ],
    ],

    'growing-business' => [
        'group' => 'solutions',
        'title' => 'Growing Business',
        'subtitle' => 'For teams of 11 to 50: scale your customer service without adding headcount',
        'sections' => [
            ['icon' => 'fa-chart-line', 'heading' => 'Grow without missing calls', 'text' => ['As call volume grows, missed calls and slow callbacks start costing real money. SureHelp absorbs the peaks, routes calls by your rules and keeps every follow-up on track across your team.']],
            ['icon' => 'fa-users', 'heading' => 'Built for teams', 'points' => [
                'Team members with their own roles: owner, manager or staff',
                'Tasks and escalations assigned to the right person, with due times',
                'Shared customer records and timelines',
                'Results and monthly reports to track performance',
                'Automations for follow-ups, thank-yous and review requests',
            ]],
            ['icon' => 'fa-rocket', 'heading' => 'Ready when you are', 'highlight' => ['Live in 48 hours', 'We set up your script, rules and calendar with you, then you forward your line. Change plans as your volume changes.']],
        ],
    ],

    'enterprise' => [
        'group' => 'solutions',
        'title' => 'Enterprise',
        'subtitle' => 'For organisations with 50+ employees, multiple locations or high call volumes',
        'sections' => [
            ['icon' => 'fa-building', 'heading' => 'Built around your operation', 'text' => ['Enterprise customers get a dedicated team, tailored call volumes and service levels written into the contract, plus custom integrations and onboarding.']],
            ['icon' => 'fa-cubes', 'heading' => 'What Enterprise includes', 'cards' => [
                ['Dedicated team', 'Agents and an account manager assigned to your organisation.'],
                ['Custom volumes and SLAs', 'Call volumes and service levels agreed in your contract.'],
                ['Bilingual support', 'Answering in the languages your customers speak.'],
                ['Integrations and API', 'Connect SureHelp to your systems and reporting.'],
                ['Compliance', 'Business Associate Agreements for healthcare, and a Data Processing Addendum.'],
                ['White-glove onboarding', 'We plan and run the rollout with your team.'],
            ]],
            ['icon' => 'fa-handshake', 'heading' => 'Let\'s talk', 'text' => ['Every enterprise setup is different. Tell us about your locations, call volumes and requirements and we\'ll put together a proposal.']],
        ],
    ],

    'health-personal-care' => $industry('Health & Personal Care', 'Call answering for clinics, dental practices, salons, spas and wellness services',
        'Patients and clients expect a friendly voice, quick booking and a reply they can rely on, even when your team is with someone else. Missed calls mean empty slots and clients who book elsewhere.',
        ['New patient and client enquiries', 'Booking, moving and cancelling appointments', 'Questions about services, prices and opening hours', 'After-hours calls and urgent concerns'],
        $common,
        'Tell us how to handle urgent symptoms or concerns. For healthcare practices we sign a Business Associate Agreement (BAA).'),

    'home-property-services' => $industry('Home & Property Services', 'Call answering for plumbers, HVAC, electricians, cleaners and property maintenance',
        'Home service calls often come in while you are on a job, and an emergency won\'t wait for a callback. Every missed call can be a lost job to the next company on the list.',
        ['Service requests and quotes', 'Emergency calls such as leaks, no heat or power problems', 'Booking visits and estimates', 'Rescheduling and follow-ups'],
        $common,
        'You decide what counts as an emergency. Those calls are escalated to you immediately; everything else is booked or scheduled for a callback.'),

    'specialty-trades' => $industry('Specialty Trades & Construction', 'Call answering for contractors, builders and skilled trades',
        'On a site or up a ladder, you can\'t take every call, but each one could be your next project. We make sure every enquiry is captured and followed up.',
        ['Project enquiries and estimate requests', 'Scheduling site visits', 'Supplier and client messages', 'Updates and callbacks'],
        $common,
        'Capture project details, budget and timing on every call, so you can prioritise the right jobs.'),

    'legal-financial' => $industry('Legal & Financial Services', 'Call answering for law firms, accountants and financial advisers',
        'A prospective client\'s first call is your first impression. They want a professional response and a consultation booked, not a voicemail box.',
        ['New client intake and consultation booking', 'Existing client messages', 'Appointment changes', 'Urgent matters escalated to the right person'],
        $common,
        'Receptionists follow your intake questions and never give legal or financial advice. Confidential details go only to your team.'),

    'education-coaching' => $industry('Education & Coaching', 'Call answering for tutors, coaches, trainers and education providers',
        'Students, parents and clients want quick answers about sessions, availability and enrolment. Every unanswered call is a lost enrolment.',
        ['Enrolment and course enquiries', 'Booking sessions and consultations', 'Rescheduling and cancellations', 'Questions about pricing and availability'],
        $common,
        'Your programmes, prices and policies are in the agents\' briefing, so every enquiry gets an accurate answer.'),

    'retail-ecommerce' => $industry('Retail & eCommerce Support', 'Customer support for shops and online stores',
        'Customers call and message about orders, products and returns. Fast, friendly answers turn questions into sales and keep customers coming back.',
        ['Product and stock questions', 'Order and delivery enquiries', 'Returns and exchanges, following your policy', 'Website chat and social messages in one inbox'],
        array_merge(array_slice($common, 0, 1), ['Website chat, Messenger and Instagram messages in one inbox', 'An AI assistant that answers common questions from your own information', 'Every conversation summarised in your portal and app']),
        'Agents follow your policies for returns, refunds and exceptions, and pass anything else to your team.'),

    'event-venue' => $industry('Event & Venue Services', 'Call answering for venues, event planners and hospitality',
        'Event enquiries are time-sensitive and valuable. Answering quickly, capturing the details and booking a viewing can be the difference between a booking and a lost date.',
        ['Event and venue enquiries', 'Booking viewings and consultations', 'Questions about capacity, dates and packages', 'Supplier and guest messages'],
        $common,
        'Capture date, guest numbers and budget on every enquiry, so your team can follow up with the right offer.'),

    'nonprofits-ministries' => $industry('Nonprofits & Ministries', 'Call answering for charities, churches and community organisations',
        'Supporters, volunteers and people looking for help all deserve a warm, reliable response, even when your team is small or volunteer-run.',
        ['Volunteer and supporter enquiries', 'Event and service information', 'Booking appointments and visits', 'Messages for your team and leaders'],
        $common,
        'Agents use your organisation\'s tone and information, and urgent pastoral or safety concerns are passed to the people you name.'),

    'franchise-businesses' => $industry('Franchise Businesses & Multi-location Chains', 'Consistent call handling across every location',
        'Customers expect the same experience at every location. SureHelp gives each location its own setup while keeping your brand standards consistent.',
        ['Calls for every location, answered consistently', 'Bookings into each location\'s calendar', 'Location-specific hours, services and prices', 'Escalations to the right local manager'],
        ['A separate account for each location, with its own hours, services and calendar', 'Brand-wide scripts and standards', 'Results for each location', 'Dedicated teams and volume pricing on Enterprise plans', 'Data kept separate between locations'],
        'Each location sees only its own calls and customers, while you keep one consistent customer experience.'),

    // Resources --------------------------------------------------------------
    'success-stories' => [
        'group' => 'resources',
        'title' => 'Success Stories',
        'subtitle' => 'How businesses use SureHelp to answer every call',
        'sections' => [
            ['icon' => 'fa-trophy', 'heading' => 'Our story is just beginning', 'text' => ['SureHelp is a young company, and we\'re building our first customer case studies with the businesses we serve. We\'ll share them here, with our customers\' permission, as they\'re ready.']],
            ['icon' => 'fa-chart-line', 'heading' => 'What we measure for every customer', 'points' => [
                'Calls answered, including nights and weekends',
                'New leads captured from calls and messages',
                'Appointments booked and their outcomes',
                'Estimated revenue from those bookings',
            ]],
            ['icon' => 'fa-handshake', 'heading' => 'Be one of our first stories', 'highlight' => ['Risk-free first month', 'Try SureHelp in real business conditions with a 30-day money-back guarantee, and see your own results in your dashboard.']],
        ],
    ],

    'roi-calculator' => [
        'group' => 'resources',
        'title' => 'ROI Calculator',
        'subtitle' => 'Measure what missed calls cost your business',
        'calculator' => true,
        'sections' => [
            ['icon' => 'fa-info-circle', 'heading' => 'How it works', 'text' => ['Enter how many calls you miss in a month, what a new customer is worth to you, and how many of those callers would have booked. The result is an estimate of the revenue going to voicemail. Nothing you enter is sent anywhere.']],
        ],
    ],

    'implementation-guide' => [
        'group' => 'resources',
        'title' => 'Implementation Guide',
        'subtitle' => 'Setup and best practices: from sign-up to answered calls in 48 hours',
        'sections' => [
            ['icon' => 'fa-clipboard-list', 'heading' => 'Day 1: We learn your business', 'points' => [
                'A setup call to agree your greeting, services, hours, service area and pricing guidance',
                'You decide what counts as urgent and how we should reach you',
                'We write your call script with you and load your FAQs into the agents\' briefing',
                'We run test calls so you hear exactly what your customers will',
            ]],
            ['icon' => 'fa-phone-volume', 'heading' => 'Day 2: Forward your line', 'points' => [
                'Forward your number all the time, after hours, or when you don\'t answer',
                'Connect your Google or Microsoft calendar so bookings land in it',
                'Invite your team and choose who receives which notifications',
                'Watch your first calls arrive in your portal and app',
            ]],
            ['icon' => 'fa-star', 'heading' => 'Best practices', 'cards' => [
                ['Keep your FAQs current', 'Update prices, services and holidays in your portal; agents see changes immediately.'],
                ['Be clear about urgent', 'A short, specific list of emergencies gets the fastest response.'],
                ['Reply to callbacks quickly', 'Follow-up tasks have due times; closing them on time keeps customers happy.'],
                ['Review your results', 'Your monthly report shows what is working and where to adjust your script.'],
            ]],
        ],
    ],

    'help-center' => [
        'group' => 'resources',
        'title' => 'Help Center',
        'subtitle' => 'Guides, answers and support for SureHelp customers',
        'sections' => [
            ['icon' => 'fa-question-circle', 'heading' => 'Find an answer', 'cards' => [
                ['Frequently asked questions', 'Common questions about calls, pricing, billing and security.'],
                ['Implementation guide', 'How setup works and best practices for great results.'],
                ['Integration directory', 'Calendars, social accounts and your website.'],
                ['Data security', 'How we protect your business and your customers.'],
            ]],
            ['icon' => 'fa-life-ring', 'heading' => 'Already a customer?', 'text' => ['Sign in to your portal and open a support request from the Support page. You can also ask us to change your call script there. Our team replies in the portal and by email.']],
            ['icon' => 'fa-envelope', 'heading' => 'Talk to us', 'text' => ['Not a customer yet, or can\'t sign in? Send us a message using the contact form or call us, and a person will get back to you within one business day.']],
        ],
        'help_links' => true,
    ],

    'webinars-training' => [
        'group' => 'resources',
        'title' => 'Webinars & Training',
        'subtitle' => 'Learn how to get the most from SureHelp',
        'sections' => [
            ['icon' => 'fa-chalkboard-teacher', 'heading' => 'Training for your team', 'text' => ['Every new customer gets a guided setup session. If you\'d like a walkthrough for your team, such as how to read call summaries, manage tasks or connect your calendar, ask us and we\'ll arrange one.']],
            ['icon' => 'fa-video', 'heading' => 'Webinars', 'text' => ['We\'re preparing live and recorded sessions on getting more from your calls and bookings. They\'ll be listed here as they\'re scheduled.']],
            ['icon' => 'fa-book-open', 'heading' => 'Learn at your own pace', 'points' => ['Implementation guide: setup and best practices', 'Frequently asked questions', 'Integration directory']],
        ],
        'help_links' => true,
    ],

    'api-documentation' => [
        'group' => 'resources',
        'title' => 'API Documentation',
        'subtitle' => 'Build on SureHelp with our REST API',
        'sections' => [
            ['icon' => 'fa-code', 'heading' => 'What the API offers', 'text' => ['The same REST API that powers the SureHelp mobile app is available for your own tools: calls, customers, appointments and availability, tasks, escalations, messages and more, always limited to what your account is allowed to see.']],
            ['icon' => 'fa-key', 'heading' => 'Conventions', 'points' => [
                'Base URL: /api/v1, JSON over HTTPS',
                'Token authentication; two-step sign-in is supported',
                'A consistent response envelope and clear error codes',
                'Rate limits on every endpoint',
                'An Idempotency-Key header on create endpoints for safe retries',
            ]],
            ['icon' => 'fa-envelope-open-text', 'heading' => 'Get access', 'highlight' => ['Request the full reference', 'API access and the full endpoint reference are available to customers and partners on request. Contact us with what you\'d like to build.']],
        ],
    ],

    // Company ----------------------------------------------------------------
    'about' => [
        'group' => 'company',
        'title' => 'About Us',
        'subtitle' => 'A new team with something to prove',
        'sections' => [
            ['icon' => 'fa-flag', 'heading' => 'Why SureHelp exists', 'text' => ['Small businesses lose customers every day for one reason: nobody picked up. SureHelp Solution answers those calls with real people, backed by software built for the job, so business owners can focus on their work.']],
            ['icon' => 'fa-heart', 'heading' => 'What we believe', 'cards' => [
                ['People first', 'Every call is answered by a trained receptionist. Technology supports them; it doesn\'t replace them.'],
                ['Earned trust', 'A risk-free first month, no long-term lock-in, and support from people who know your account.'],
                ['Careful with data', 'Access only for the people serving your business, and every access recorded.'],
                ['Measured by results', 'We show you calls answered, leads and bookings, so you can judge us on outcomes.'],
            ]],
            ['icon' => 'fa-map-marker-alt', 'heading' => 'Where to find us', 'address' => true],
        ],
    ],

    'careers' => [
        'group' => 'company',
        'title' => 'Careers',
        'subtitle' => 'Help us make sure no customer call goes unanswered',
        'sections' => [
            ['icon' => 'fa-users', 'heading' => 'Working at SureHelp', 'text' => ['We\'re a growing team of receptionists, supervisors and builders who care about doing right by small businesses and their customers. Every agent gets structured training through SureHelp Agent University.']],
            ['icon' => 'fa-briefcase', 'heading' => 'Open positions', 'text' => ['We don\'t have openings listed right now. We\'re always glad to hear from friendly, reliable people with customer service experience: send us a short note about yourself and the role you\'re interested in, and we\'ll keep it on file.']],
        ],
    ],

    'press' => [
        'group' => 'company',
        'title' => 'Press & Media',
        'subtitle' => 'News, information and media enquiries',
        'sections' => [
            ['icon' => 'fa-newspaper', 'heading' => 'About SureHelp Solution', 'text' => ['SureHelp Solution provides 24/7 live call answering, appointment booking and customer management for small and growing businesses, with real receptionists backed by a client portal, mobile app and AI tools for messaging.']],
            ['icon' => 'fa-download', 'heading' => 'Brand assets', 'text' => ['Our logo is available below for editorial use. Please don\'t alter its colours or proportions.'], 'logo' => true],
            ['icon' => 'fa-envelope', 'heading' => 'Media enquiries', 'text' => ['For interviews, quotes or information, contact us using the details below and we\'ll respond promptly.'], 'address' => true],
        ],
    ],

    'partners' => [
        'group' => 'company',
        'title' => 'Partner Program',
        'subtitle' => 'Grow with SureHelp',
        'sections' => [
            ['icon' => 'fa-handshake', 'heading' => 'Who we partner with', 'cards' => [
                ['Agencies and consultants', 'Marketing, web and business consultants whose clients need every call answered.'],
                ['Software providers', 'Tools for appointments, field service or customer management that want to connect with SureHelp.'],
                ['Industry associations', 'Groups that want to offer their members a reliable answering service.'],
            ]],
            ['icon' => 'fa-gift', 'heading' => 'Why partner with us', 'points' => [
                'A service your clients can trust, with a 30-day money-back guarantee',
                'Fast setup: clients are live within 48 hours',
                'An API and integrations for software partners',
                'A team that will work with you on what your clients need',
            ]],
            ['icon' => 'fa-paper-plane', 'heading' => 'Get in touch', 'text' => ['Tell us about your business and your clients, and we\'ll talk about how we can work together.']],
        ],
    ],

];
