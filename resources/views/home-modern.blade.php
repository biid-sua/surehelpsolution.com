@extends('layouts.modern')

{{-- The Modern home page (D121), in layouts.modern. Shown when it's chosen in Admin › Appearance, or
     while previewing it. The plans come from Admin › Billing › Plans (D98). --}}
@php
    $icon = fn (string $name, string $class = '') => \App\Support\Ui\SiteIcons::svg($name, $class);

    $money = fn (?int $cents) => $cents === null ? null : (fmod($cents / 100, 1) == 0 ? number_format($cents / 100) : number_format($cents / 100, 2));
    $saving = $plans->filter(fn ($p) => $p->annual_monthly_cents && $p->price_cents > 0 && $p->interval === 'month')
        ->map(fn ($p) => (int) round((1 - $p->annual_monthly_cents / $p->price_cents) * 100))->max();
    $trialDays = (int) $plans->where('contact_only', false)->max('trial_days');
    // Self-serve plans are cards; "talk to us" plans get a wide row underneath.
    [$custom, $listed] = $plans->partition(fn ($p) => $p->contact_only && $plans->where('contact_only', false)->isNotEmpty());

    $industries = [
        ['home-property-services', 'Home & property services', 'Plumbing, HVAC, electrical, cleaning'],
        ['health-personal-care', 'Health & personal care', 'Dental, clinics, salons, spas'],
        ['legal-financial', 'Legal & financial', 'Law firms, accountants, insurance'],
        ['specialty-trades', 'Specialty trades', 'Roofing, pest control, auto, landscaping'],
        ['education-coaching', 'Education & coaching', 'Tutors, coaches, fitness studios'],
        ['retail-ecommerce', 'Retail & e-commerce', 'Shops and online stores'],
        ['event-venue', 'Events & venues', 'Planners, venues, rentals'],
        ['nonprofits-ministries', 'Nonprofits & ministries', 'Churches, charities, community groups'],
        ['franchise-businesses', 'Franchises', 'Many locations, one standard'],
    ];
    $chips = ['Plumbers', 'HVAC', 'Dental offices', 'Law firms', 'Salons & spas', 'Electricians', 'Accountants', 'Roofers', 'Fitness studios', 'Clinics', 'Property managers', 'Cleaning services', 'Pest control', 'Auto repair'];
@endphp

@section('title', config('site.meta.home.title'))
@section('description', config('site.meta.home.description'))

@push('schema')
{!! \App\Support\SiteSchema::script([
    \App\Support\SiteSchema::organization(),
    \App\Support\SiteSchema::website(),
    \App\Support\SiteSchema::service('24/7 live call answering service', config('site.meta.home.description'), url('/')),
]) !!}
@endpush

@push('head')
    <link rel="preload" as="image" href="{{ asset('assets/img/agent.webp') }}">
@endpush

@section('content')
    {{-- Hero --}}
    <section class="hero">
        <div class="wrap hero-grid">
            <div class="hero-copy">
                <span class="pill rv"><span class="dot"></span>Real receptionists answering right now, 24/7</span>
                <h1 class="h-display rv d1">Every call answered. <span class="serif">Every</span> <span class="mark">customer</span> cared for.</h1>
                <p class="lead rv d2">Sure Help's live receptionists answer your phone in your business's name, day and night. They book the job, take the details and reach you when it's urgent. Your calendar, customers and messages sit in one simple app.</p>
                <div class="hero-ctas rv d3">
                    <a class="btn btn-primary" href="{{ route('signup') }}">Start your free trial {{ $icon('arrow') }}</a>
                    <a class="btn btn-ghost" href="#contact" data-sales-chat-open="demo">Book a demo</a>
                </div>
                <ul class="ticks rv d3">
                    <li>{{ $icon('check') }}Live in about 48 hours</li>
                    <li>{{ $icon('check') }}Keep your phone number</li>
                    <li>{{ $icon('check') }}30-day money-back guarantee</li>
                </ul>
            </div>

            <div class="stage rv d2" aria-label="Example: a receptionist answering a call for a plumbing business">
                <div class="agent-card">
                    <img src="{{ asset('assets/img/agent.webp') }}" alt="" width="640" height="720" fetchpriority="high">
                    <span class="live"><i></i> On a call</span>
                </div>
                <div class="call" aria-hidden="true">
                    <div class="call-top">
                        <span class="call-ava">{{ $icon('phone') }}</span>
                        <span class="call-who"><b>Rivera Plumbing</b><span>Answered by Ava</span></span>
                        <span class="wave"><i></i><i></i><i></i><i></i><i></i></span>
                        <span class="call-time" data-timer>0:12</span>
                    </div>
                    <div class="lines">
                        <p class="line agent">Rivera Plumbing, this is Ava. How can I help?</p>
                        <p class="line caller">Water's coming through my kitchen ceiling!</p>
                        <p class="line agent">Let's shut off the main valve. I'm getting Mike for you now.</p>
                    </div>
                </div>
                <div class="toast t1" aria-hidden="true">
                    <span class="ic">{{ $icon('calendar') }}</span>
                    <span><b>Job booked</b><span>Thu, 9:00 AM · 14 Oak St</span></span>
                </div>
                <div class="toast t2" aria-hidden="true">
                    <span class="ic">{{ $icon('message') }}</span>
                    <span><b>Text sent to Mike</b><span>Urgent: leak, caller on hold</span></span>
                </div>
            </div>
        </div>
    </section>

    {{-- Who it's for --}}
    <div class="strip" aria-label="Businesses we answer for">
        <div class="wrap strip-in">
            <span class="strip-label">Answering for</span>
            <div class="marquee">
                <div class="marquee-track">
                    @foreach ([...$chips, ...$chips] as $i => $chip)
                        <span class="chip" @if ($i >= count($chips)) aria-hidden="true" @endif>{{ $chip }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- What missed calls cost --}}
    <section class="sec" aria-labelledby="cost-title">
        <div class="cost">
            <div class="wrap cost-grid">
                <div class="cost-copy rv">
                    <span class="eyebrow">The cost of voicemail</span>
                    <h2 class="h-section" id="cost-title">A missed call is a job <span class="serif">someone else</span> gets.</h2>
                    <p class="lead">Most callers won't leave a message. They call the next business on the list. Move the sliders to see what unanswered calls could be costing you.</p>
                </div>
                <div class="calc rv d1" data-calc role="group" aria-label="Missed call calculator">
                    <label>
                        <span class="row"><span>Calls you miss each week</span><output data-out="calls">5</output></span>
                        <input type="range" name="calls" min="1" max="50" value="5">
                    </label>
                    <label>
                        <span class="row"><span>Average job value</span><output data-out="value">$300</output></span>
                        <input type="range" name="value" min="50" max="3000" step="50" value="300">
                    </label>
                    <label>
                        <span class="row"><span>Callers who would have booked</span><output data-out="rate">30%</output></span>
                        <input type="range" name="rate" min="5" max="80" step="5" value="30">
                    </label>
                    <div class="calc-result" aria-live="polite">
                        <span>Work you could be missing each month</span>
                        <span class="big" data-out="month">$1,950</span>
                        <small>About <b data-out="year">$23,400</b> a year, from your own numbers.</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section class="sec" id="features" aria-labelledby="features-title" style="padding-top: 24px">
        <div class="wrap">
            <div class="sec-head rv">
                <span class="eyebrow">One place for your front office</span>
                <h2 class="h-section" id="features-title">People who answer. <span class="serif">Software</span> that keeps up.</h2>
                <p class="lead">Every call, booking and message lands in the same place, so nothing slips and everyone sees the same thing.</p>
            </div>
            <div class="bento">
                <article class="tile hero-tile rv">
                    <span class="ico">{{ $icon('phone') }}</span>
                    <h3>Live call answering, 24/7</h3>
                    <p>Trained receptionists answer in your business's name with your greeting, your prices and your rules. Emergencies reach you right away. Everything else is booked or written up.</p>
                    <ul class="mini-list">
                        <li>{{ $icon('check') }}Nights, weekends and holidays included</li>
                        <li>{{ $icon('check') }}Warm transfers to you or your team</li>
                        <li>{{ $icon('check') }}English and Spanish for your callers</li>
                    </ul>
                    <div class="feed" aria-hidden="true">
                        <div><i class="st"></i><b>New job booked</b> Water heater check<span>2 min ago</span></div>
                        <div><i class="st v"></i><b>Message taken</b> Quote for a bathroom remodel<span>18 min ago</span></div>
                        <div><i class="st a"></i><b>Urgent, transferred</b> Burst pipe, Elm St<span>1 h ago</span></div>
                    </div>
                </article>
                <article class="tile rv d1">
                    <span class="ico v">{{ $icon('calendar') }}</span>
                    <h3>Booking and calendar</h3>
                    <p>Appointments go straight into your calendar, synced with Google or Microsoft, with reminders that cut no-shows.</p>
                </article>
                <article class="tile rv d2">
                    <span class="ico a">{{ $icon('message') }}</span>
                    <h3>One inbox</h3>
                    <p>Texts, website chat, Facebook, Instagram and email in one list, answered by you or by us.</p>
                </article>
                <article class="tile wide rv">
                    <span class="ico">{{ $icon('bolt') }}</span>
                    <h3>Missed-call text-back and instant lead replies</h3>
                    <p>If a caller hangs up, they get a friendly text in seconds. New web leads get an answer before they call someone else.</p>
                </article>
                <article class="tile wide rv d1">
                    <span class="ico v">{{ $icon('users') }}</span>
                    <h3>Customers and follow-ups</h3>
                    <p>Every customer's calls, notes and jobs on one timeline, with tasks, service reminders and win-back messages.</p>
                </article>
                <article class="tile rv">
                    <span class="ico a">{{ $icon('card') }}</span>
                    <h3>Get paid faster</h3>
                    <p>Send estimates and invoices, take deposits and let customers pay by card from a text.</p>
                </article>
                <article class="tile rv d1">
                    <span class="ico">{{ $icon('star') }}</span>
                    <h3>Reviews and growth</h3>
                    <p>Ask for Google reviews after each job, plus social posts, website tools and SEO.</p>
                </article>
                <article class="tile rv d2">
                    <span class="ico v">{{ $icon('chart') }}</span>
                    <h3>Clear results</h3>
                    <p>See calls answered, jobs booked and what it's worth in dollars, every month.</p>
                </article>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section class="sec" id="how" aria-labelledby="how-title" style="background: var(--paper-2)">
        <div class="wrap">
            <div class="sec-head center rv">
                <span class="eyebrow">How it works</span>
                <h2 class="h-section" id="how-title">Live in about <span class="serif">two days.</span></h2>
                <p class="lead">No new phone system and no new number. You forward your calls; we do the rest.</p>
            </div>
            <ol class="steps" style="padding: 0; margin: 0; list-style: none">
                <li class="step rv">
                    <span class="n">1</span>
                    <span class="when">Day 1</span>
                    <h3>Tell us how you work</h3>
                    <ul>
                        <li>{{ $icon('check') }}Your greeting, services and prices</li>
                        <li>{{ $icon('check') }}What's urgent and who to call</li>
                        <li>{{ $icon('check') }}When and how to book</li>
                    </ul>
                </li>
                <li class="step rv d1">
                    <span class="n">2</span>
                    <span class="when">Day 2</span>
                    <h3>Forward your calls</h3>
                    <ul>
                        <li>{{ $icon('check') }}Keep your number and your phone</li>
                        <li>{{ $icon('check') }}All calls, or only after hours and when you're busy</li>
                        <li>{{ $icon('check') }}Test calls before you go live</li>
                    </ul>
                </li>
                <li class="step rv d2">
                    <span class="n">3</span>
                    <span class="when">Every day after</span>
                    <h3>Get back to the work</h3>
                    <ul>
                        <li>{{ $icon('check') }}Jobs booked while you work</li>
                        <li>{{ $icon('check') }}A summary of every call in the app</li>
                        <li>{{ $icon('check') }}Rules you can change any time</li>
                    </ul>
                </li>
            </ol>
        </div>
    </section>

    {{-- People + rules --}}
    <section class="sec" aria-labelledby="people-title">
        <div class="wrap split">
            <div class="split-visual rv" aria-hidden="true">
                <img src="{{ asset('assets/img/agent.webp') }}" alt="" width="640" height="720" loading="lazy">
                <div class="rules">
                    <b>Your rules, on screen for every call</b>
                    <div><em>Leak or flood</em> Transfer to Mike right away</div>
                    <div><em>Quote</em> Book a free visit this week</div>
                    <div><em>Sales call</em> Take a message</div>
                </div>
            </div>
            <div class="rv d1" style="display: grid; gap: 18px">
                <span class="eyebrow">Real people, your way</span>
                <h2 class="h-section" id="people-title">Callers hear a <span class="serif">person,</span> not a menu.</h2>
                <p class="lead">Every receptionist sees your business's details and rules the moment a call comes in, so they answer like part of your team.</p>
                <div class="points">
                    <div class="point"><span class="ico">{{ $icon('shield') }}</span><div><h3>Emergencies reach you</h3><p>You decide what's urgent. Those calls are transferred to you or texted right away.</p></div></div>
                    <div class="point"><span class="ico v">{{ $icon('users') }}</span><div><h3>Coached every week</h3><p>Supervisors listen to calls and coach receptionists, so the service keeps getting better.</p></div></div>
                    <div class="point"><span class="ico a">{{ $icon('clock') }}</span><div><h3>Always on</h3><p>Lunch breaks, late nights, holidays and busy Mondays are all covered.</p></div></div>
                </div>
            </div>
        </div>
    </section>

    {{-- Industries --}}
    <section class="sec" id="industries" aria-labelledby="ind-title" style="padding-top: 0">
        <div class="wrap">
            <div class="sec-head rv">
                <span class="eyebrow">Industries</span>
                <h2 class="h-section" id="ind-title">Made for businesses that <span class="serif">live</span> on the phone.</h2>
            </div>
            <div class="ind-grid">
                @foreach ($industries as $i => [$slug, $name, $examples])
                    <a class="ind rv d{{ $i % 3 }}" href="{{ route('pages.show', $slug) }}">
                        <span><b>{{ $name }}</b><span>{{ $examples }}</span></span>
                        {{ $icon('arrow', 'go') }}
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Pricing --}}
    <section class="sec" id="pricing" aria-labelledby="pricing-title" style="background: var(--paper-2)" data-pricing data-period="monthly">
        <div class="wrap">
            <div class="sec-head center rv">
                <span class="eyebrow">Pricing</span>
                <h2 class="h-section" id="pricing-title">Simple plans. <span class="serif">No surprises.</span></h2>
                <p class="lead">Pick a plan, try it free and change or cancel any time.</p>
                @if ($saving)
                    <div class="toggle" role="group" aria-label="Billing period">
                        <button type="button" data-period-btn="monthly" aria-pressed="true">Monthly</button>
                        <button type="button" data-period-btn="annual" aria-pressed="false">Yearly <span class="save">Save {{ $saving }}%</span></button>
                    </div>
                @endif
            </div>

            @if ($plans->isEmpty())
                <div class="rv" style="text-align: center; display: grid; gap: 20px; justify-items: center">
                    <p class="lead">Our plans are being updated. Tell us about your business and we'll send you pricing today.</p>
                    <a class="btn btn-primary" href="#contact">Talk to us {{ $icon('arrow') }}</a>
                </div>
            @else
                <div class="plans" style="--cols: {{ min(4, $listed->count()) }}">
                    @foreach ($listed->values() as $i => $plan)
                        @php
                            $items = $plan->highlights();
                            if (isset($plan->limits['extra_call_cents'])) {
                                $items[] = 'Extra calls $'.number_format($plan->limits['extra_call_cents'] / 100, 2).' each';
                            }
                            $period = $plan->interval === 'year' ? '/year' : '/month';
                            $annual = $plan->annual_monthly_cents && $plan->interval === 'month';
                        @endphp
                        <article @class(['plan', 'rv', 'd'.($i % 3), 'featured' => $plan->is_featured])>
                            @if ($plan->is_featured)<span class="badge">Most popular</span>@endif
                            <div>
                                <h3>{{ $plan->name }}</h3>
                                @if ($plan->tagline)<p class="tag">{{ $plan->tagline }}</p>@endif
                            </div>
                            <div>
                                @if ($plan->contact_only)
                                    <div class="price"><span class="amt">Custom</span></div>
                                    <p class="price-note">Priced for your call volume</p>
                                @else
                                    <div class="price">
                                        <span class="amt" data-monthly>${{ $money($plan->price_cents) }}</span>
                                        <span class="amt" data-annual>${{ $money($annual ? $plan->annual_monthly_cents : $plan->price_cents) }}</span>
                                        <span class="per">{{ $period }}</span>
                                    </div>
                                    <p class="price-note">
                                        <span data-monthly>{{ $plan->trial_days > 0 ? $plan->trial_days.'-day free trial' : 'Billed '.($plan->interval === 'year' ? 'yearly' : 'monthly') }}</span>
                                        <span data-annual>{{ $annual ? 'Per month, billed yearly' : ($plan->trial_days > 0 ? $plan->trial_days.'-day free trial' : '') }}</span>
                                    </p>
                                @endif
                            </div>
                            <ul>
                                @foreach (array_slice($items, 0, 7) as $item)
                                    <li>{{ $icon('check') }}<span>{{ $item }}</span></li>
                                @endforeach
                            </ul>
                            @if ($plan->contact_only)
                                <a class="btn btn-ghost" href="#contact">Talk to us</a>
                            @else
                                <a @class(['btn', 'btn-primary' => ! $plan->is_featured, 'btn-ghost' => $plan->is_featured]) href="{{ route('signup', ['plan' => $plan->slug]) }}">{{ $plan->trial_days > 0 ? 'Start free trial' : 'Get started' }} {{ $icon('arrow') }}</a>
                            @endif
                        </article>
                    @endforeach
                </div>
                @foreach ($custom as $plan)
                    <article class="plan-wide rv">
                        <div>
                            <h3>{{ $plan->name }}</h3>
                            <p class="tag">{{ $plan->tagline ?: 'For many locations or high call volume.' }}</p>
                        </div>
                        <ul>
                            @foreach (array_slice($plan->highlights(), 0, 6) as $item)
                                <li>{{ $icon('check') }}<span>{{ $item }}</span></li>
                            @endforeach
                        </ul>
                        <a class="btn btn-ghost" href="#contact">Talk to us {{ $icon('arrow') }}</a>
                    </article>
                @endforeach
            @endif

            <div class="plans-foot rv">
                <span>{{ $icon('shield') }}30-day money-back guarantee</span>
                <span>{{ $icon('refresh') }}No long-term contract</span>
                <span>{{ $icon('clock') }}Live in about 48 hours</span>
            </div>
        </div>
    </section>

    {{-- Trust --}}
    <section class="sec" aria-labelledby="trust-title">
        <div class="wrap">
            <div class="sec-head rv">
                <span class="eyebrow">Privacy and security</span>
                <h2 class="h-section" id="trust-title">Your customers' details, <span class="serif">kept safe.</span></h2>
            </div>
            <div class="trust">
                <a class="rv" href="{{ route('legal.business-associate-agreement') }}"><span class="ico">{{ $icon('shield') }}</span><b>HIPAA mode</b><span>For practices: a Business Associate Agreement and patient details kept inside Sure Help.</span></a>
                <a class="rv d1" href="{{ route('legal.data-security') }}"><span class="ico v">{{ $icon('lock') }}</span><b>Encrypted</b><span>Data is encrypted in transit and at rest, with two-step sign-in for staff.</span></a>
                <a class="rv d2" href="{{ route('legal.data-processing-addendum') }}"><span class="ico a">{{ $icon('doc') }}</span><b>Data Processing Addendum</b><span>Clear terms for how we handle your customers' information.</span></a>
                <a class="rv d3" href="{{ route('legal.privacy-policy') }}"><span class="ico">{{ $icon('message') }}</span><b>Consent first</b><span>Texts and emails only go to people who agreed to get them.</span></a>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="sec" id="faq" aria-labelledby="faq-title" style="padding-top: 0">
        <div class="wrap faq-grid">
            <div class="rv" style="display: grid; gap: 18px">
                <span class="eyebrow">Questions</span>
                <h2 class="h-section" id="faq-title">Good to <span class="serif">know.</span></h2>
                <p class="lead">Something else on your mind? <a href="{{ route('legal.faq') }}" style="color: var(--mint-ink); font-weight: 600">See all questions</a> or <a href="#contact" style="color: var(--mint-ink); font-weight: 600">ask us</a>.</p>
            </div>
            <div class="faq rv d1">
                <details open>
                    <summary>Are the receptionists real people? {{ $icon('plus') }}</summary>
                    <p>Yes. Trained live receptionists answer your calls in your business's name, using your greeting, details and rules.</p>
                </details>
                <details>
                    <summary>Do I need a new phone number or system? {{ $icon('plus') }}</summary>
                    <p>No. You keep your number and forward calls to us: all of them, or only after hours and when you can't pick up.</p>
                </details>
                <details>
                    <summary>How quickly can we start? {{ $icon('plus') }}</summary>
                    <p>Most businesses are live in about 48 hours. We set up your greeting and rules on day one and make test calls before going live.</p>
                </details>
                <details>
                    <summary>What happens with urgent calls? {{ $icon('plus') }}</summary>
                    <p>You decide what's urgent. Those calls are transferred to you or your team, or you get a text right away. Everything else is booked or written up for you.</p>
                </details>
                <details>
                    <summary>Does it work with my calendar and tools? {{ $icon('plus') }}</summary>
                    <p>Sure Help works with Google and Microsoft calendars, QuickBooks Online, Jobber, Housecall Pro and Zapier. <a href="{{ route('pages.show', 'integrations') }}">See integrations</a>.</p>
                </details>
                <details>
                    <summary>Can I try it first? {{ $icon('plus') }}</summary>
                    <p>Yes. @if ($trialDays > 0)Start a {{ $trialDays }}-day free trial, and @else Start today, and @endif if you're not happy in your first 30 days, you get your money back.</p>
                </details>
            </div>
        </div>
    </section>

    {{-- Contact --}}
    <section class="sec" id="contact" aria-labelledby="contact-title" style="background: var(--paper-2)">
        <div class="wrap contact-grid">
            <div class="contact-side rv">
                <span class="eyebrow">Talk to us</span>
                <h2 class="h-section" id="contact-title">Let's get your phones <span class="serif">covered.</span></h2>
                <p class="lead">Tell us a little about your business. We'll reply within one business day with answers and pricing.</p>
                <div class="contact-ways">
                    <a href="mailto:{{ config('company.email') }}"><span class="ico">{{ $icon('mail') }}</span><span><b>Email</b><span>{{ config('company.email') }}</span></span></a>
                    <div><span class="ico v">{{ $icon('pin') }}</span><span><b>Mailing address</b><span>{{ config('company.address.line1') }}, {{ config('company.address.line2') }}</span></span></div>
                </div>
            </div>

            <form class="form rv d1" action="{{ route('contact.store') }}" method="POST" novalidate>
                @csrf
                @if (session('contact_success'))
                    <div class="alert ok" role="status">{{ session('contact_success') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert bad" role="alert"><b>Please check the form:</b>
                        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="two">
                    <div class="field">
                        <label for="c-name">Full name</label>
                        <input id="c-name" name="name" value="{{ old('name') }}" required autocomplete="name" @error('name') aria-invalid="true" @enderror>
                    </div>
                    <div class="field">
                        <label for="c-company">Business <small>(optional)</small></label>
                        <input id="c-company" name="company" value="{{ old('company') }}" autocomplete="organization">
                    </div>
                </div>
                <div class="two">
                    <div class="field">
                        <label for="c-email">Email</label>
                        <input id="c-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" @error('email') aria-invalid="true" @enderror>
                    </div>
                    <div class="field">
                        <label for="c-phone">Phone <small>(optional)</small></label>
                        <input id="c-phone" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel">
                    </div>
                </div>
                <div class="field">
                    <label for="c-type">How can we help?</label>
                    <select id="c-type" name="inquiry_type" required @error('inquiry_type') aria-invalid="true" @enderror>
                        <option value="" disabled @selected(! old('inquiry_type'))>Choose one</option>
                        <option value="sales" @selected(old('inquiry_type') === 'sales')>Pricing and plans</option>
                        <option value="demo" @selected(old('inquiry_type') === 'demo')>Book a demo</option>
                        <option value="general" @selected(old('inquiry_type') === 'general')>General question</option>
                        <option value="enterprise" @selected(old('inquiry_type') === 'enterprise')>Many locations or high call volume</option>
                        <option value="support" @selected(old('inquiry_type') === 'support')>Help with my account</option>
                    </select>
                </div>
                <div class="field">
                    <label for="c-msg">Your message</label>
                    <textarea id="c-msg" name="message" required placeholder="Your business, roughly how many calls you get, and what you'd like covered." @error('message') aria-invalid="true" @enderror>{{ old('message') }}</textarea>
                </div>
                <label class="check">
                    <input type="checkbox" name="privacy" value="1" @checked(old('privacy')) required>
                    <span>I agree to the <a href="{{ route('legal.privacy-policy') }}" target="_blank" rel="noopener">Privacy Policy</a>.</span>
                </label>
                <label class="check">
                    <input type="checkbox" name="sms_consent" value="1" @checked(old('sms_consent'))>
                    <span>(Optional) I agree to receive communications by text message about inquiries, confirm appoints, schedule appointments from Sure Help Solution. You may opt-out by replying STOP or ask for more information by replying HELP. Message frequency varies. Message and data rates may apply. You may review our <a href="{{ route('legal.privacy-policy') }}" target="_blank" rel="noopener">Privacy Policy</a> to learn how your data is used.</span>
                </label>
                <p class="note">Texting is optional and never a condition of anything. We only text you if you tick the box above.</p>
                <button type="submit" class="btn btn-primary">Send message {{ $icon('arrow') }}</button>
            </form>
        </div>
    </section>

    {{-- The missed-call game (D122) --}}
    @if (config('game.enabled'))
    <section class="sec" aria-labelledby="game-title" style="padding-bottom: 0">
        <div class="wrap">
            <a class="game-band rv" href="{{ route('game') }}">
                <span class="ico">{{ $icon('phone') }}</span>
                <span><b id="game-title">Hands full? Play the 60-second missed-call game.</b><span>Fix the leaks while the phone rings, then see what changes when {{ config('game.brand.receptionist') }} answers.</span></span>
                <span class="btn btn-primary btn-sm">Play now {{ $icon('arrow') }}</span>
            </a>
        </div>
    </section>
    @endif

    {{-- Final call to action --}}
    <section class="sec" aria-labelledby="final-title">
        <div class="final">
            <div class="wrap">
                <h2 class="h-section rv" id="final-title">Never miss the <span class="serif">next call.</span></h2>
                <p class="lead rv d1">Real receptionists, 24/7, in your business's name. Set up in about two days.</p>
                <div class="hero-ctas rv d2">
                    <a class="btn btn-mint" href="{{ route('signup') }}">Start your free trial {{ $icon('arrow') }}</a>
                    <a class="btn btn-light" href="#contact" data-sales-chat-open="demo">Book a demo</a>
                </div>
            </div>
        </div>
    </section>
@endsection
