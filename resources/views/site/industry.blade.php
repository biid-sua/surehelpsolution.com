<x-site.layout :title="$marketing['headline']" :description="$marketing['intro']">
    <x-site.page-hero :eyebrow="$details['label']" :title="$marketing['headline']" :lead="$marketing['intro']">
        <a href="{{ route('site.contact', ['topic' => 'setup']) }}" class="btn-mint">Start your free setup</a>
        <a href="{{ route('site.contact', ['topic' => 'demo']) }}" class="btn-light">Book a demo</a>
    </x-site.page-hero>

    <section class="bg-white py-20 sm:py-28">
        <div class="site-container grid gap-10 lg:grid-cols-2">
            <div class="rounded-3xl border border-slate-200 p-8">
                <h2 class="text-xl font-semibold text-slate-900">Jobs we book for you</h2>
                <p class="mt-2 text-slate-600">We start from what {{ \Illuminate\Support\Str::lower($details['label']) }} businesses usually offer, then use your own services, durations and prices.</p>
                <ul class="mt-6 divide-y divide-slate-100" role="list">
                    @foreach ($details['services'] as $s)
                        <li class="flex items-center justify-between gap-4 py-3 text-[15px]">
                            <span class="flex items-center gap-3 text-slate-800"><x-ui.icon name="calendar" class="size-5 text-brand-600" /> {{ $s['name'] }}</span>
                            <span class="text-sm text-slate-500">{{ $s['minutes'] }} min</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="space-y-6">
                @if (filled($details['emergencies']))
                    <div class="rounded-3xl bg-navy-900 p-8 text-white">
                        <h2 class="flex items-center gap-3 text-xl font-semibold"><x-ui.icon name="alert" class="size-6 text-mint-300" /> Urgent calls, handled your way</h2>
                        <p class="mt-4 leading-7 text-slate-300">Out of the box, agents treat these as urgent and escalate them to you straight away: {{ \Illuminate\Support\Str::lcfirst($details['emergencies']) }}</p>
                        <p class="mt-4 text-sm text-slate-400">You can change what counts as urgent at any time.</p>
                    </div>
                @endif
                <div class="rounded-3xl border border-slate-200 p-8">
                    <h2 class="text-xl font-semibold text-slate-900">Questions callers ask, answered your way</h2>
                    <ul class="mt-5 space-y-3" role="list">
                        @foreach ($details['faqs'] as [$question])
                            <li class="flex gap-3 text-[15px] text-slate-700"><x-ui.icon name="chat" class="mt-0.5 size-5 shrink-0 text-brand-600" /> "{{ $question }}"</li>
                        @endforeach
                    </ul>
                    <p class="mt-5 text-sm text-slate-500">You give the answers once; every agent uses them.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="site-container">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Every account includes</h2>
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['24/7 live answering in your business name', 'Bookings into your Google or Microsoft calendar', 'Call summaries in your portal and app', 'Callbacks tracked as tasks with due times'] as $point)
                    <div class="flex gap-3 rounded-2xl bg-white p-5 text-[15px] text-slate-700 ring-1 ring-slate-200"><x-ui.icon name="check-circle" class="size-5 shrink-0 text-mint-600" /> {{ $point }}</div>
                @endforeach
            </div>
            <p class="mt-10 text-sm text-slate-600">Other industries:
                @foreach ($others as $slug => $o)
                    <a href="{{ route('site.industries.show', $slug) }}" class="font-medium text-brand-600 hover:text-brand-700">{{ $o['label'] }}</a>@if (! $loop->last), @endif
                @endforeach
            </p>
        </div>
    </section>

    <x-site.cta />
</x-site.layout>
