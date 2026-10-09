@props(['title', 'lead' => null, 'updated' => null])
<x-site.layout :title="$title" :description="$lead ?? $title">
    <x-site.page-hero eyebrow="Trust & legal" :title="$title" :lead="$lead" />

    <section class="bg-white py-16 sm:py-20">
        <div class="site-container">
            <div class="mb-10 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-6 print:hidden">
                @if ($updated)<p class="text-sm text-slate-500">{{ $updated }}</p>@endif
                <button type="button" onclick="window.print()" class="btn-outline !py-2">
                    <x-ui.icon name="download" class="size-4" /> Print or save as PDF
                </button>
            </div>
            <div class="legal-prose mx-auto lg:mx-0">
                {{ $slot }}
            </div>
            <p class="mt-16 max-w-3xl text-sm text-slate-500">Questions about this document? Email <a href="mailto:{{ config('company.email') }}" class="font-medium text-brand-600 underline underline-offset-2">{{ config('company.email') }}</a> or call <a href="tel:{{ config('marketing.phone_href') }}" class="font-medium text-brand-600 underline underline-offset-2">{{ config('marketing.phone') }}</a>.</p>
        </div>
    </section>
</x-site.layout>
