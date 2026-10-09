@props(['eyebrow' => null, 'title', 'lead' => null])
{{-- The navy band at the top of inner pages; the fixed header sits on it. --}}
<section class="site-glow relative overflow-hidden pt-36 pb-20 text-white sm:pt-40 sm:pb-24">
    <div class="site-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
    <div class="site-container relative">
        <div class="max-w-3xl">
            @if ($eyebrow)<p class="eyebrow text-mint-300">{{ $eyebrow }}</p>@endif
            <h1 class="mt-4 text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{{ $title }}</h1>
            @if ($lead)<p class="mt-6 text-lg leading-8 text-pretty text-slate-300">{{ $lead }}</p>@endif
            @if (! $slot->isEmpty())<div class="mt-8 flex flex-wrap gap-3">{{ $slot }}</div>@endif
        </div>
    </div>
</section>
