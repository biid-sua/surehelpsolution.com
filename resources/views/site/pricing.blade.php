<x-site.layout title="Pricing" description="Simple monthly plans for 24/7 live call answering and appointment booking, from ${{ collect($plans)->pluck('price')->filter()->min() }} a month. No setup fees, cancel any time, 30-day money-back guarantee.">
    <x-site.page-hero eyebrow="Pricing" title="Simple plans. Every call covered." lead="Nights, weekends and holidays included. No setup fees, no long-term contract, and a 30-day money-back guarantee on your first month." />

    <section class="bg-white py-20 sm:py-24" x-data="pricing">
        <div class="site-container">
            <div class="flex justify-center">
                <div class="inline-flex rounded-full bg-slate-100 p-1 text-sm font-semibold" role="group" aria-label="Billing period">
                    <button type="button" x-on:click="annual = false" :aria-pressed="!annual" :class="!annual ? 'bg-white text-slate-900 shadow' : 'text-slate-500'" class="rounded-full px-5 py-2">Monthly</button>
                    <button type="button" x-on:click="annual = true" :aria-pressed="annual" :class="annual ? 'bg-white text-slate-900 shadow' : 'text-slate-500'" class="rounded-full px-5 py-2">Annual <span class="ml-1 rounded-full bg-mint-200 px-2 py-0.5 text-xs text-mint-600">Save 10%</span></button>
                </div>
            </div>

            <div class="mt-12 grid gap-6 lg:grid-cols-4">
                @foreach ($plans as $plan)
                    @php($featured = $plan['featured'] ?? false)
                    <div @class(['relative flex flex-col rounded-3xl p-7', 'bg-navy-900 text-white shadow-2xl ring-2 ring-mint-400 lg:-my-3 lg:py-10' => $featured, 'border border-slate-200 bg-white' => ! $featured])>
                        @if ($featured)<p class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-mint-400 px-3 py-1 text-xs font-semibold text-navy-950">Most popular</p>@endif
                        <h2 class="text-lg font-semibold">{{ $plan['name'] }}</h2>
                        <p @class(['mt-1 text-sm', 'text-slate-300' => $featured, 'text-slate-500' => ! $featured])>{{ $plan['for'] }}</p>
                        <p class="mt-6 flex items-baseline gap-1">
                            @if ($plan['price'])
                                <span class="text-4xl font-semibold tracking-tight">$<span x-text="price({{ $plan['price'] }})">{{ $plan['price'] }}</span></span>
                                <span @class(['text-sm', 'text-slate-300' => $featured, 'text-slate-500' => ! $featured])>/month</span>
                            @else
                                <span class="text-4xl font-semibold tracking-tight">Custom</span>
                            @endif
                        </p>
                        <p @class(['mt-1 h-5 text-xs', 'text-slate-400' => $featured, 'text-slate-500' => ! $featured])>@if ($plan['price'])<span x-show="annual" x-cloak>Billed yearly at $<span x-text="price({{ $plan['price'] }}) * 12"></span></span>@endif</p>
                        <p @class(['mt-4 text-sm font-medium', 'text-mint-300' => $featured, 'text-brand-600' => ! $featured])>{{ $plan['minutes'] }} · {{ $plan['overage'] }}</p>
                        <ul class="mt-6 flex-1 space-y-3 text-sm" role="list">
                            @foreach ($plan['features'] as $feature)
                                <li class="flex gap-3"><x-ui.icon name="check-circle" :class="$featured ? 'size-5 shrink-0 text-mint-300' : 'size-5 shrink-0 text-mint-600'" /> <span @class(['text-slate-200' => $featured, 'text-slate-700' => ! $featured])>{{ $feature }}</span></li>
                            @endforeach
                        </ul>
                        <a href="{{ route('site.contact', ['topic' => $plan['price'] ? 'setup' : 'enterprise']) }}" @class(['mt-8', 'btn-mint' => $featured, 'btn-dark' => ! $featured])>{{ $plan['price'] ? 'Start with '.$plan['name'] : 'Contact sales' }}</a>
                    </div>
                @endforeach
            </div>
            <p class="mt-10 text-center text-sm text-slate-500">Prices in US dollars, before any applicable taxes. Minutes reset each month; extra calls are billed at your plan's rate.</p>
        </div>
    </section>

    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="site-container">
            <div class="grid gap-6 md:grid-cols-3">
                @foreach ([['shield', '30-day money-back guarantee', 'If SureHelp isn\'t right for you in your first month, we refund it.'], ['clock', 'Live in 48 hours', 'Setup is included and done with you. No hardware, no software to install.'], ['check-circle', 'No lock-in', 'Monthly plans you can change or cancel any time.']] as [$icon, $title, $text])
                    <div class="rounded-3xl bg-white p-7 ring-1 ring-slate-200">
                        <x-ui.icon :name="$icon" class="size-7 text-brand-600" />
                        <h2 class="mt-4 font-semibold text-slate-900">{{ $title }}</h2>
                        <p class="mt-2 text-[15px] leading-7 text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-white py-20 sm:py-24">
        <div class="site-container max-w-4xl">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Pricing questions</h2>
            <div class="mt-8"><x-site.faq :groups="['Pricing and billing' => config('marketing.faqs')['Pricing and billing'], 'Getting started' => config('marketing.faqs')['Getting started']]" /></div>
        </div>
    </section>

    <x-site.cta title="Not sure which plan fits?" lead="Tell us roughly how many calls you get and we'll recommend a plan. If your volume changes, switching plans takes a minute." />
</x-site.layout>
