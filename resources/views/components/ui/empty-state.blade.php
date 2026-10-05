@props(['icon' => 'inbox', 'title', 'description' => null])

{{-- Spec §69: say what is missing, why it matters, and what to do next. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="rounded-full bg-surface-2 p-3 text-subtle ring-1 ring-line"><x-ui.icon :name="$icon" class="size-6" /></span>
    <h3 class="mt-4 text-sm font-semibold text-ink">{!! e($title, false) !!}</h3>
    @if ($description)<p class="mt-1 max-w-sm text-sm text-muted">{!! e($description, false) !!}</p>@endif
    @if (! $slot->isEmpty())<div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>@endif
</div>
