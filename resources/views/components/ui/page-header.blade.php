@props(['title', 'description' => null, 'back' => null])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-end justify-between gap-4']) }}>
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-ink">
                <x-ui.icon name="arrow-left" class="size-4" /> Back
            </a>
        @endif
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{!! e($title, false) !!}</h1>
        @if ($description)<p class="mt-1 text-sm text-muted">{!! e($description, false) !!}</p>@endif
    </div>
    @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</div>
