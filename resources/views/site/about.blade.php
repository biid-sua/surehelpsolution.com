<x-site.layout title="About us" description="SureHelp Solution answers calls and books appointments for small and growing businesses, with trained people backed by software built for the job.">
    <x-site.page-hero eyebrow="About SureHelp" title="We answer the phone so you can run your business" lead="SureHelp started with a simple observation: small businesses lose customers every day for one reason. Nobody picked up. We built a service that fixes that properly, with real people backed by software made for the job." />

    <section class="bg-white py-20 sm:py-28">
        <div class="site-container grid gap-16 lg:grid-cols-2">
            <div>
                <h2 class="text-3xl font-semibold tracking-tight text-slate-900">What we believe</h2>
                <div class="mt-6 space-y-5 text-lg leading-8 text-slate-600">
                    <p>A caller deserves a person, not a voicemail box. Someone who uses your business name, knows your services and does what you would do.</p>
                    <p>You deserve to know exactly what happened on every call, the moment it happened, without chasing anyone for it.</p>
                    <p>And trust has to be earned. We're a new team, so we offer a risk-free first month, no long-term contracts and support from people who know your account.</p>
                </div>
            </div>
            <ul class="grid gap-5 sm:grid-cols-2" role="list">
                @foreach ([
                    ['users', 'People first', 'Trained receptionists answer every call. Technology supports them; it doesn\'t replace them.'],
                    ['shield', 'Careful with data', 'Access only for the people serving your business, two-step sign-in for our staff, and every access logged.'],
                    ['chart', 'Measured by results', 'We show you calls answered, leads and bookings, so you can judge us on outcomes.'],
                    ['sparkles', 'Always improving', 'Calls are reviewed, scripts refined and agents coached, every week.'],
                ] as [$icon, $title, $text])
                    <li class="rounded-3xl border border-slate-200 p-6">
                        <x-ui.icon :name="$icon" class="size-7 text-brand-600" />
                        <h3 class="mt-4 font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-2 text-[15px] leading-7 text-slate-600">{{ $text }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="site-container grid gap-10 lg:grid-cols-3">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Company details</h2>
                <p class="mt-3 text-slate-600">SureHelp Solution is a US company.</p>
            </div>
            <dl class="grid gap-6 sm:grid-cols-3 lg:col-span-2">
                <div class="rounded-2xl bg-white p-6 ring-1 ring-slate-200"><dt class="text-sm text-slate-500">Registered address</dt><dd class="mt-2 font-medium text-slate-900">{{ config('company.address.line1') }}<br>{{ config('company.address.line2') }}</dd></div>
                <div class="rounded-2xl bg-white p-6 ring-1 ring-slate-200"><dt class="text-sm text-slate-500">Phone</dt><dd class="mt-2"><a href="tel:{{ config('marketing.phone_href') }}" class="font-medium text-slate-900 hover:text-brand-600">{{ config('marketing.phone') }}</a></dd></div>
                <div class="rounded-2xl bg-white p-6 ring-1 ring-slate-200"><dt class="text-sm text-slate-500">Email</dt><dd class="mt-2 break-all"><a href="mailto:{{ config('company.email') }}" class="font-medium text-slate-900 hover:text-brand-600">{{ config('company.email') }}</a></dd></div>
            </dl>
        </div>
    </section>

    <x-site.cta title="Give us a month to prove it" />
</x-site.layout>
