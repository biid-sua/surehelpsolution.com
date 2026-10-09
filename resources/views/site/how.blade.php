<x-site.layout title="How it works" description="From sign-up to answered calls in 48 hours: we learn your business, you forward your line, and every call appears in your portal.">
    <x-site.page-hero eyebrow="How it works" title="Answering your calls in 48 hours" lead="No new phone system, no software to install. We learn your business, you forward your line, and from then on every call is answered and in your portal.">
        <a href="{{ route('site.contact', ['topic' => 'setup']) }}" class="btn-mint">Start your free setup</a>
    </x-site.page-hero>

    <section class="bg-white py-20 sm:py-28">
        <div class="site-container">
            <ol class="relative space-y-16 border-l border-slate-200 pl-10 lg:ml-6" role="list">
                @foreach ([
                    ['Day 1', 'We learn your business', ['A short setup call: your greeting, services, hours, service area and pricing guidance.', 'You tell us what counts as urgent and how to reach you when it happens.', 'We write your call script with you and load your FAQs into the agents\' briefing.', 'We run test calls so you hear exactly what your customers will.']],
                    ['Day 2', 'Forward your line', ['Forward your existing number to SureHelp: always, after hours, or when you don\'t pick up.', 'Calls are answered in your business name by receptionists assigned to your account.', 'Each call appears in your portal and app with the caller, reason, outcome and notes.', 'Bookings land in your calendar; callbacks become tasks with due times.']],
                    ['Every week', 'Better every week', ['Supervisors review calls and coach agents on your account.', 'You flag anything to change, and we update the script for every agent.', 'Your results page shows calls answered, leads, bookings and estimated revenue.', 'A monthly report arrives on the 1st.']],
                ] as $i => [$when, $title, $points])
                    <li class="relative">
                        <span class="absolute top-0 -left-[3.6rem] flex size-10 items-center justify-center rounded-full bg-navy-900 text-sm font-semibold text-mint-300 ring-8 ring-white">{{ $i + 1 }}</span>
                        <p class="text-xs font-semibold tracking-wide text-brand-600 uppercase">{{ $when }}</p>
                        <h2 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h2>
                        <ul class="mt-5 grid gap-3 sm:grid-cols-2" role="list">
                            @foreach ($points as $point)
                                <li class="flex gap-3 rounded-2xl bg-slate-50 p-4 text-[15px] leading-6 text-slate-700"><x-ui.icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-mint-600" /> {{ $point }}</li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="site-container max-w-4xl">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Questions about getting started</h2>
            <div class="mt-8"><x-site.faq :groups="['Getting started' => config('marketing.faqs')['Getting started']]" /></div>
        </div>
    </section>

    <x-site.cta />
</x-site.layout>
