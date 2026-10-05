@props(['tone' => 'info', 'title' => null])

@php
    $tones = [
        'info' => ['border-sky-500/30 bg-sky-500/10 text-sky-200', 'info'],
        'success' => ['border-emerald-500/30 bg-emerald-500/10 text-emerald-200', 'check-circle'],
        'warning' => ['border-amber-500/30 bg-amber-500/10 text-amber-200', 'alert'],
        'danger' => ['border-red-500/30 bg-red-500/10 text-red-200', 'alert'],
    ];
    [$toneClasses, $icon] = $tones[$tone] ?? $tones['info'];
@endphp

<div role="{{ in_array($tone, ['warning', 'danger'], true) ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => 'flex gap-3 rounded-xl border px-4 py-3 text-sm '.$toneClasses]) }}>
    <x-ui.icon :name="$icon" class="mt-0.5 size-5 shrink-0" />
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{!! e($title, false) !!}</p>
        @endif
        <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
    </div>
</div>
