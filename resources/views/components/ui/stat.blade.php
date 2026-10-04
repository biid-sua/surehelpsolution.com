@props([
    'label',
    'value',
    'icon' => null,
    'change' => null,      // percentage vs previous period, null = not comparable
    'hint' => null,
    'href' => null,
    'invert' => false,     // true when "up" is bad (e.g. missed calls)
])

@php
    $good = $change === null ? null : ($invert ? $change <= 0 : $change >= 0);
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'group block rounded-[var(--radius-card)] border border-line bg-surface p-5 shadow-[var(--shadow-card)] transition-colors'.($href ? ' hover:border-brand-500/50 hover:bg-surface-2' : '')]) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-muted">{{ $label }}</p>
        @if ($icon)
            <span class="rounded-lg bg-brand-500/15 p-2 text-brand-300"><x-ui.icon :name="$icon" class="size-5" /></span>
        @endif
    </div>
    <p class="mt-2 text-3xl font-semibold tracking-tight text-ink tabular-nums">{{ $value }}</p>
    <div class="mt-2 flex items-center gap-2 text-xs">
        @if ($change !== null)
            <span @class(['inline-flex items-center gap-1 font-medium', 'text-emerald-300' => $good, 'text-red-300' => ! $good])>
                <x-ui.icon :name="$change >= 0 ? 'trend-up' : 'trend-down'" class="size-3.5" />
                {{ $change > 0 ? '+' : '' }}{{ $change }}%
            </span>
        @endif
        @if ($hint)<span class="text-subtle">{{ $hint }}</span>@endif
    </div>
</{{ $tag }}>
