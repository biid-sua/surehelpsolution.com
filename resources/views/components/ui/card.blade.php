@props(['title' => null, 'description' => null, 'padding' => true])

<section {{ $attributes->merge(['class' => 'rounded-[var(--radius-card)] border border-line bg-surface shadow-[var(--shadow-card)]']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4">
            <div class="min-w-0">
                @if ($title)<h2 class="text-base font-semibold text-ink">{!! e($title, false) !!}</h2>@endif
                @if ($description)<p class="mt-0.5 text-sm text-muted">{!! e($description, false) !!}</p>@endif
            </div>
            @isset($actions)<div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['p-5' => $padding])>
        {{ $slot }}
    </div>
</section>
