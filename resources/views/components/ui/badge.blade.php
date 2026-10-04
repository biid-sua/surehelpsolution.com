@props(['tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'bg-white/5 text-muted ring-line-strong',
        'brand' => 'bg-brand-500/15 text-brand-300 ring-brand-500/30',
        'success' => 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
        'completed' => 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
        'warning' => 'bg-amber-500/15 text-amber-300 ring-amber-500/30',
        'progress' => 'bg-amber-500/15 text-amber-300 ring-amber-500/30',
        'danger' => 'bg-red-500/15 text-red-300 ring-red-500/30',
        'scheduled' => 'bg-brand-500/15 text-brand-300 ring-brand-500/30',
        'info' => 'bg-sky-500/15 text-sky-300 ring-sky-500/30',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '.($tones[$tone] ?? $tones['neutral'])]) }}>{{ $slot }}</span>
