@props([
    'variant' => 'primary', // primary | secondary | ghost | danger
    'size' => 'md',         // sm | md
    'href' => null,
    'icon' => null,
    'type' => 'button',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $sizes = ['sm' => 'px-3 py-1.5 text-sm', 'md' => 'px-4 py-2 text-sm'];
    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-500 shadow-sm',
        'secondary' => 'border border-line-strong bg-surface-2 text-ink hover:bg-surface-3',
        'ghost' => 'text-muted hover:bg-surface-2 hover:text-ink',
        'danger' => 'bg-red-600/90 text-white hover:bg-red-500',
    ];
    $classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </button>
@endif
