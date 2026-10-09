<x-site.layout title="Industries" description="Call answering and appointment booking built around how your industry works: plumbing, HVAC, electrical, cleaning, dental, salons, law firms and auto repair.">
    <x-site.page-hero eyebrow="Industries" title="Answering that knows your trade" lead="Every business gets its own script, but some things are true for a whole industry: the services people ask for, the questions they have and what counts as an emergency. Our agents start with that knowledge." />

    <section class="bg-white py-20 sm:py-28">
        <div class="site-container grid gap-6 md:grid-cols-2 lg:grid-cols-4">
            @foreach ($industries as $slug => $industry)
                <a href="{{ route('site.industries.show', $slug) }}" class="group flex flex-col rounded-3xl border border-slate-200 p-7 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-xl">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $industry['label'] }}</h2>
                    <p class="mt-3 flex-1 text-[15px] leading-7 text-slate-600">{{ $industry['intro'] }}</p>
                    <span class="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-brand-600">See how we help <x-ui.icon name="chevron-right" class="size-4 transition group-hover:translate-x-0.5" /></span>
                </a>
            @endforeach
        </div>
        <div class="site-container mt-12">
            <div class="rounded-3xl bg-slate-50 p-8 text-center ring-1 ring-slate-200">
                <p class="text-lg font-semibold text-slate-900">Don't see your industry?</p>
                <p class="mt-2 text-slate-600">We answer for all kinds of businesses. Tell us how your calls work and we'll build the script around it.</p>
                <a href="{{ route('site.contact', ['topic' => 'sales']) }}" class="btn-dark mt-6">Talk to us</a>
            </div>
        </div>
    </section>

    <x-site.cta />
</x-site.layout>
