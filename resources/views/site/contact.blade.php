@php
    $headings = [
        'demo' => ['Book a demo', 'See how calls are answered, booked and reported for a business like yours. Tell us a good time and we\'ll set it up.'],
        'setup' => ['Start your free setup', 'Tell us about your business. We\'ll reply within one business day with a plan and a go-live date. There\'s no setup fee.'],
        'enterprise' => ['Talk to us about Enterprise', 'Multiple locations, high volume or special requirements? Tell us what you need.'],
    ];
    [$title, $lead] = $headings[$topic] ?? ['Contact us', 'Questions about SureHelp, pricing or your account? Send us a message or call, and a person will get back to you.'];
@endphp
<x-site.layout :title="$title" :description="$lead">
    <x-site.page-hero eyebrow="Contact" :title="$title" :lead="$lead" />

    <section class="bg-white py-20 sm:py-24">
        <div class="site-container grid gap-14 lg:grid-cols-5">
            <div class="rounded-3xl bg-slate-50 p-6 ring-1 ring-slate-200 sm:p-10 lg:col-span-3">
                <x-site.contact-form :topic="$topic" />
            </div>
            <div class="space-y-8 lg:col-span-2">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Talk to a person</h2>
                    <a href="tel:{{ config('marketing.phone_href') }}" class="mt-2 block text-2xl font-semibold text-brand-600 hover:text-brand-700">{{ config('marketing.phone') }}</a>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Email</h2>
                    <a href="mailto:{{ config('company.email') }}" class="mt-2 block break-all text-slate-700 hover:text-brand-600">{{ config('company.email') }}</a>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Address</h2>
                    <p class="mt-2 text-slate-700">{{ config('company.address.line1') }}<br>{{ config('company.address.line2') }}</p>
                </div>
                <div class="rounded-2xl bg-navy-900 p-6 text-white">
                    <h2 class="font-semibold">Already a customer?</h2>
                    <p class="mt-2 text-sm text-slate-300">Sign in to your portal to see calls, update your script or open a support request.</p>
                    <a href="{{ route('login') }}" class="btn-mint mt-5 !py-2.5">Sign in</a>
                </div>
            </div>
        </div>
    </section>
</x-site.layout>
