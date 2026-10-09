<x-site.layout>
    @php
        $services = config('marketing.services');
        $industries = collect(config('marketing.industries'))->keys();
        $faqs = collect(config('marketing.faqs'))->flatMap(fn ($items) => $items)->take(6)->all();
        $testimonials = config('marketing.testimonials');
        $from = collect(config('marketing.plans'))->pluck('price')->filter()->min();
    @endphp

    {{-- Hero --}}
    <section class="site-glow relative overflow-hidden pt-32 pb-20 text-white sm:pt-40 lg:pb-28">
        <div class="site-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
        <div class="site-container relative grid items-center gap-16 lg:grid-cols-12">
            <div class="lg:col-span-6">
                <p class="inline-flex items-center gap-2 rounded-full bg-white/5 px-3 py-1 text-xs font-medium text-mint-200 ring-1 ring-white/10">
                    <span class="size-1.5 rounded-full bg-mint-400"></span> Live receptionists, 24 hours a day
                </p>
                <h1 class="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-6xl">Every call answered. <span class="block bg-gradient-to-r from-mint-300 to-brand-300 bg-clip-text text-transparent">Every customer booked.</span></h1>
                <p class="mt-6 max-w-xl text-lg leading-8 text-pretty text-slate-300">Real people answer your business calls in your name, book appointments straight into your calendar and send you every detail, so you can keep your hands on the work that pays.</p>
                <div class="mt-9 flex flex-wrap gap-3">
                    <a href="{{ route('site.contact', ['topic' => 'setup']) }}" class="btn-mint">Start your free setup</a>
                    <a href="{{ route('site.contact', ['topic' => 'demo']) }}" class="btn-light">Book a demo</a>
                </div>
                <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-400" role="list">
                    @foreach (['Live in 48 hours', 'No setup fees', 'Cancel any time', '30-day money-back guarantee'] as $point)
                        <li class="flex items-center gap-2"><x-ui.icon name="check-circle" class="size-4 text-mint-400" /> {{ $point }}</li>
                    @endforeach
                </ul>
            </div>

            {{-- A call, as your customers and you experience it. --}}
            <div class="relative lg:col-span-6" aria-hidden="true">
                <div class="relative mx-auto aspect-square max-w-md">
                    <div class="absolute inset-6 rounded-full bg-gradient-to-br from-brand-500/40 via-navy-700 to-mint-500/30 blur-2xl"></div>
                    <div class="absolute inset-0 overflow-hidden rounded-[2.5rem] bg-gradient-to-b from-navy-700 to-navy-900 ring-1 ring-white/10">
                        <img src="{{ asset('assets/img/agent.png') }}" alt="" class="absolute bottom-0 left-1/2 h-[92%] w-auto -translate-x-1/2 object-contain" width="836" height="940" fetchpriority="high">
                    </div>
                    <div class="site-float absolute top-8 left-2 w-60 rounded-2xl bg-white/95 p-4 text-slate-900 shadow-2xl sm:-left-10">
                        <p class="flex items-center gap-2 text-xs font-semibold text-mint-600"><span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-mint-400 opacity-75"></span><span class="relative inline-flex size-2 rounded-full bg-mint-500"></span></span> Incoming call</p>
                        <p class="mt-2 text-sm font-semibold">"Thanks for calling Rivera Plumbing, this is Ana."</p>
                        <p class="mt-1 text-xs text-slate-500">Answered on the 2nd ring · New customer</p>
                    </div>
                    <div class="site-float-delayed absolute right-2 bottom-24 w-64 rounded-2xl bg-white/95 p-4 text-slate-900 shadow-2xl sm:-right-8">
                        <p class="flex items-center gap-2 text-xs font-semibold text-brand-600"><x-ui.icon name="calendar" class="size-4" /> Appointment booked</p>
                        <p class="mt-2 text-sm font-semibold">Drain cleaning · Tue 10:00 AM</p>
                        <p class="mt-1 text-xs text-slate-500">Added to your Google Calendar</p>
                    </div>
                    <div class="absolute bottom-4 left-4 flex items-center gap-3 rounded-full bg-navy-950/80 py-2 pr-4 pl-2 text-xs text-white ring-1 ring-white/10 backdrop-blur">
                        <span class="flex size-7 items-center justify-center rounded-full bg-mint-400 text-navy-950"><x-ui.icon name="bell" class="size-4" /></span>
                        Call summary sent to your phone
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Promises --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="site-container grid grid-cols-2 gap-px overflow-hidden lg:grid-cols-4">
            @foreach (config('marketing.promises') as $p)
                <div class="px-4 py-8 text-center sm:px-6">
                    <p class="text-3xl font-semibold tracking-tight text-navy-900">{{ $p['value'] }}</p>
                    <p class="mt-2 text-sm text-slate-600">{{ $p['label'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- What missed calls cost --}}
    <section class="bg-slate-50 py-20 sm:py-28">
        <div class="site-container grid items-center gap-12 lg:grid-cols-2">
            <div>
                <p class="eyebrow text-brand-600">The cost of voicemail</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight text-balance text-slate-900 sm:text-4xl">Every missed call is a customer calling your competitor</h2>
                <p class="mt-5 text-lg leading-8 text-slate-600">Most callers won't leave a message. They hang up and dial the next business on the list. A receptionist who always answers turns those calls into booked work.</p>
                <p class="mt-4 text-slate-600">Try your own numbers. Nothing you enter leaves this page.</p>
            </div>
            <div x-data="missedCalls" class="rounded-3xl bg-white p-6 shadow-xl ring-1 ring-slate-900/5 sm:p-8">
                <div class="space-y-6">
                    <label class="block">
                        <span class="flex justify-between text-sm font-medium text-slate-700">Calls you miss each month <span class="font-semibold text-slate-900" x-text="calls"></span></span>
                        <input type="range" min="1" max="200" x-model.number="calls" class="mt-3 w-full accent-brand-600">
                    </label>
                    <label class="block">
                        <span class="flex justify-between text-sm font-medium text-slate-700">Average value of a new customer <span class="font-semibold text-slate-900" x-text="money(value)"></span></span>
                        <input type="range" min="25" max="2000" step="25" x-model.number="value" class="mt-3 w-full accent-brand-600">
                    </label>
                    <label class="block">
                        <span class="flex justify-between text-sm font-medium text-slate-700">Callers who would have booked <span class="font-semibold text-slate-900"><span x-text="rate"></span>%</span></span>
                        <input type="range" min="5" max="80" step="5" x-model.number="rate" class="mt-3 w-full accent-brand-600">
                    </label>
                </div>
                <div class="mt-8 grid grid-cols-2 gap-4 rounded-2xl bg-navy-900 p-5 text-white">
                    <div><p class="text-xs text-slate-400">Revenue lost per month</p><p class="mt-1 text-2xl font-semibold text-mint-300" x-text="money(monthly)"></p></div>
                    <div><p class="text-xs text-slate-400">Per year</p><p class="mt-1 text-2xl font-semibold" x-text="money(yearly)"></p></div>
                </div>
                <p class="mt-4 text-xs text-slate-500">An estimate from your inputs, not a promise of results. Plans start at ${{ $from }} a month.</p>
            </div>
        </div>
    </section>

    {{-- Services --}}
    <section class="bg-white py-20 sm:py-28">
        <div class="site-container">
            <div class="mx-auto max-w-2xl text-center">
                <p class="eyebrow text-brand-600">Everything a front desk does</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight text-balance text-slate-900 sm:text-4xl">People on the phone, software behind them</h2>
                <p class="mt-5 text-lg leading-8 text-slate-600">Trained receptionists handle the conversation. Your portal handles everything after it.</p>
            </div>
            <div class="mt-14 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($services as $slug => $s)
                    <a href="{{ route('site.services.show', $slug) }}" class="group relative flex flex-col rounded-3xl border border-slate-200 bg-white p-7 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-xl">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white"><x-ui.icon :name="$s['icon']" class="size-6" /></span>
                        <h3 class="mt-6 text-lg font-semibold text-slate-900">{{ $s['nav'] }}</h3>
                        <p class="mt-2 flex-1 text-[15px] leading-7 text-slate-600">{{ $s['summary'] }}</p>
                        <span class="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-brand-600">Learn more <x-ui.icon name="chevron-right" class="size-4 transition group-hover:translate-x-0.5" /></span>
                    </a>
                @endforeach
                <a href="{{ route('site.how') }}" class="site-glow flex flex-col justify-between rounded-3xl p-7 text-white">
                    <div>
                        <p class="eyebrow text-mint-300">How it works</p>
                        <h3 class="mt-4 text-2xl font-semibold">From sign-up to answered calls in 48 hours</h3>
                    </div>
                    <span class="mt-8 inline-flex items-center gap-1 text-sm font-semibold text-mint-300">See the steps <x-ui.icon name="chevron-right" class="size-4" /></span>
                </a>
            </div>
        </div>
    </section>

    {{-- The portal --}}
    <section class="site-glow relative overflow-hidden py-20 text-white sm:py-28">
        <div class="site-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
        <div class="site-container relative grid items-center gap-14 lg:grid-cols-2">
            <div>
                <p class="eyebrow text-mint-300">Your client portal</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">See every call, booking and follow-up the moment it happens</h2>
                <ul class="mt-8 space-y-5" role="list">
                    @foreach ([
                        ['Instant call summaries', 'Who called, why, what was promised and what happens next, in the portal, the app and your inbox.'],
                        ['Calendar that stays true', 'Bookings land in your Google or Microsoft calendar, and your busy times stop double bookings.'],
                        ['Follow-ups you can trust', 'Callbacks and urgent issues become tasks with owners and due times.'],
                        ['Results in dollars', 'Calls answered, leads and bookings, with an estimate of the revenue they bring in.'],
                    ] as [$title, $text])
                        <li class="flex gap-4">
                            <span class="mt-1 flex size-6 shrink-0 items-center justify-center rounded-full bg-mint-400/15 text-mint-300"><x-ui.icon name="check-circle" class="size-4" /></span>
                            <span><span class="block font-semibold">{{ $title }}</span><span class="mt-1 block text-slate-300">{{ $text }}</span></span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-3xl bg-white/5 p-3 ring-1 ring-white/10 backdrop-blur" aria-hidden="true">
                <div class="rounded-2xl bg-navy-950 p-5 ring-1 ring-white/5">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold">Today · Rivera Plumbing</p>
                        <span class="rounded-full bg-mint-400/15 px-2.5 py-1 text-xs font-medium text-mint-300">Live</span>
                    </div>
                    <div class="mt-5 grid grid-cols-3 gap-3">
                        @foreach ([['Calls answered', '27'], ['Bookings', '9'], ['Est. revenue', '$3,140']] as [$label, $value])
                            <div class="rounded-xl bg-white/5 p-3"><p class="text-[11px] text-slate-400">{{ $label }}</p><p class="mt-1 text-lg font-semibold">{{ $value }}</p></div>
                        @endforeach
                    </div>
                    <ul class="mt-5 divide-y divide-white/5 text-sm" role="list">
                        @foreach ([['9:12 AM', 'Leak under kitchen sink', 'Booked · Tue 10:00', 'text-mint-300'], ['9:40 AM', 'Quote for water heater', 'Callback task · due 2 PM', 'text-amber-300'], ['10:05 AM', 'Burst pipe, basement flooding', 'Escalated to you', 'text-red-300'], ['10:31 AM', 'Asked about weekend hours', 'Answered', 'text-slate-400']] as [$time, $reason, $outcome, $tone])
                            <li class="flex items-center justify-between gap-3 py-3">
                                <span class="flex min-w-0 items-center gap-3"><span class="w-16 shrink-0 text-xs text-slate-500">{{ $time }}</span><span class="truncate">{{ $reason }}</span></span>
                                <span class="shrink-0 text-xs {{ $tone }}">{{ $outcome }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section class="bg-white py-20 sm:py-28">
        <div class="site-container">
            <div class="mx-auto max-w-2xl text-center">
                <p class="eyebrow text-brand-600">How it works</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Live in two days. Better every week.</h2>
            </div>
            <ol class="mt-14 grid gap-6 lg:grid-cols-3" role="list">
                @foreach ([
                    ['Day 1', 'We learn your business', 'A short call to set up your greeting, services, hours, pricing guidance and what counts as urgent. We write the script with you and run test calls.'],
                    ['Day 2', 'Forward your line', 'Forward your number all the time, after hours or when you\'re busy. Calls are answered in your name and every one appears in your portal.'],
                    ['Ongoing', 'We keep improving', 'We review calls, refine the script with your feedback, and your results page shows what\'s working.'],
                ] as $i => [$when, $title, $text])
                    <li class="relative rounded-3xl border border-slate-200 p-7">
                        <span class="flex size-10 items-center justify-center rounded-full bg-navy-900 text-sm font-semibold text-mint-300">{{ $i + 1 }}</span>
                        <p class="mt-6 text-xs font-semibold tracking-wide text-brand-600 uppercase">{{ $when }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-3 text-[15px] leading-7 text-slate-600">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Industries --}}
    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="site-container">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <div class="max-w-2xl">
                    <p class="eyebrow text-brand-600">Industries</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Trained on how your industry works</h2>
                    <p class="mt-4 text-lg text-slate-600">Agents arrive knowing the services, questions and emergencies that come with your kind of business.</p>
                </div>
                <a href="{{ route('site.industries.index') }}" class="btn-outline">All industries</a>
            </div>
            <div class="mt-10 grid grid-cols-2 gap-4 md:grid-cols-4">
                @foreach ($industries as $key)
                    <a href="{{ route('site.industries.show', $key) }}" class="group flex items-center justify-between rounded-2xl bg-white px-5 py-4 text-sm font-semibold text-slate-900 ring-1 ring-slate-200 transition hover:ring-brand-300">
                        {{ config("industries.{$key}.label") }} <x-ui.icon name="chevron-right" class="size-4 text-slate-400 group-hover:text-brand-600" />
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Who we are / proof --}}
    <section class="bg-white py-20 sm:py-28">
        <div class="site-container">
            @if (count($testimonials))
                <div class="mx-auto max-w-2xl text-center">
                    <p class="eyebrow text-brand-600">Customers</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">In their words</h2>
                </div>
                <div class="mt-14 grid gap-6 lg:grid-cols-3">
                    @foreach ($testimonials as $t)
                        <figure class="rounded-3xl border border-slate-200 p-7">
                            <blockquote class="text-[15px] leading-7 text-slate-700">"{{ $t['quote'] }}"</blockquote>
                            <figcaption class="mt-6 text-sm"><span class="font-semibold text-slate-900">{{ $t['name'] }}</span><span class="block text-slate-500">{{ $t['business'] }}, {{ $t['city'] }}</span></figcaption>
                        </figure>
                    @endforeach
                </div>
            @else
                <div class="grid items-center gap-12 rounded-3xl bg-slate-50 p-8 ring-1 ring-slate-200 sm:p-12 lg:grid-cols-2">
                    <div>
                        <p class="eyebrow text-brand-600">A new team with something to prove</p>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight text-balance text-slate-900 sm:text-4xl">We'd rather earn your trust than ask for it</h2>
                        <p class="mt-5 text-lg leading-8 text-slate-600">SureHelp is a young company, and we're hungry to prove ourselves with real service, fast support and zero fluff. That's why your first month is risk-free.</p>
                        <a href="{{ route('site.about') }}" class="mt-8 inline-flex items-center gap-1 font-semibold text-brand-600 hover:text-brand-700">More about us <x-ui.icon name="chevron-right" class="size-4" /></a>
                    </div>
                    <ul class="grid gap-4 sm:grid-cols-2" role="list">
                        @foreach ([['shield', '30-day money-back guarantee', 'Not happy in your first month? You get your money back.'], ['phone', 'Real people from day one', 'Every call is answered by a trained receptionist.'], ['clock', 'Fast, human support', 'Talk to a person who knows your account.'], ['check-circle', 'No lock-in', 'Monthly plans. Cancel any time.']] as [$icon, $title, $text])
                            <li class="rounded-2xl bg-white p-5 ring-1 ring-slate-200">
                                <x-ui.icon :name="$icon" class="size-6 text-brand-600" />
                                <p class="mt-3 font-semibold text-slate-900">{{ $title }}</p>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $text }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </section>

    {{-- Pricing teaser --}}
    <section class="bg-white pb-20 sm:pb-28">
        <div class="site-container">
            <div class="flex flex-col items-start justify-between gap-8 rounded-3xl border border-slate-200 p-8 sm:p-12 lg:flex-row lg:items-center">
                <div class="max-w-2xl">
                    <p class="eyebrow text-brand-600">Pricing</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-900">Plans from ${{ $from }} a month</h2>
                    <p class="mt-3 text-lg text-slate-600">Less than the cost of a part-time receptionist, with nights, weekends and holidays covered. Save 10% with annual billing.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('site.pricing') }}" class="btn-dark">Compare plans</a>
                    <a href="{{ route('site.contact', ['topic' => 'sales']) }}" class="btn-outline">Talk to sales</a>
                </div>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="bg-slate-50 py-20 sm:py-28">
        <div class="site-container grid gap-12 lg:grid-cols-3">
            <div>
                <p class="eyebrow text-brand-600">FAQ</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-900">Questions, answered</h2>
                <p class="mt-4 text-slate-600">Can't find what you need? <a href="{{ route('legal.faq') }}" class="font-semibold text-brand-600 hover:text-brand-700">See all questions</a> or <a href="#contact-form" class="font-semibold text-brand-600 hover:text-brand-700">ask us</a>.</p>
            </div>
            <div class="lg:col-span-2"><x-site.faq :groups="['Common questions' => $faqs]" /></div>
        </div>
    </section>

    {{-- Contact --}}
    <section id="contact" class="scroll-mt-20 bg-white py-20 sm:py-28">
        <div class="site-container grid gap-14 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <p class="eyebrow text-brand-600">Get started</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Let's get your phones answered</h2>
                <p class="mt-5 text-lg leading-8 text-slate-600">Tell us a little about your business. We'll reply within one business day with a plan and a go-live date.</p>
                <dl class="mt-10 space-y-6 text-sm">
                    <div class="flex gap-4"><dt><x-ui.icon name="phone" class="size-6 text-brand-600" /><span class="sr-only">Phone</span></dt><dd><a href="tel:{{ config('marketing.phone_href') }}" class="font-semibold text-slate-900 hover:text-brand-600">{{ config('marketing.phone') }}</a><span class="block text-slate-500">Talk to us now</span></dd></div>
                    <div class="flex gap-4"><dt><x-ui.icon name="inbox" class="size-6 text-brand-600" /><span class="sr-only">Email</span></dt><dd><a href="mailto:{{ config('company.email') }}" class="font-semibold text-slate-900 hover:text-brand-600">{{ config('company.email') }}</a><span class="block text-slate-500">Replies within one business day</span></dd></div>
                    <div class="flex gap-4"><dt><x-ui.icon name="building" class="size-6 text-brand-600" /><span class="sr-only">Address</span></dt><dd class="text-slate-600">{{ config('company.address.line1') }}<br>{{ config('company.address.line2') }}</dd></div>
                </dl>
            </div>
            <div class="rounded-3xl bg-slate-50 p-6 ring-1 ring-slate-200 sm:p-10 lg:col-span-3">
                <x-site.contact-form />
            </div>
        </div>
    </section>
</x-site.layout>
