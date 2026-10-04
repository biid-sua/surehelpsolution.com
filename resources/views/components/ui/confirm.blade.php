@props(['title' => 'Are you sure?', 'confirmLabel' => 'Confirm', 'tone' => 'danger'])

{{--
    Accessible confirmation dialog (spec §7). Usage:
    <x-ui.confirm id="remove-5" title="Remove agent?" confirm-label="Remove" action="unassign(5)">
        <x-slot:trigger><x-ui.button variant="ghost">Remove</x-ui.button></x-slot:trigger>
        Agents lose access immediately.
    </x-ui.confirm>
--}}
<div x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="inline-block">
    <span x-on:click="open = true">{{ $trigger }}</span>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="confirm-title-{{ $attributes->get('id', 'x') }}">
            <div class="absolute inset-0 bg-black/60" x-on:click="open = false"></div>
            <div x-show="open" x-transition x-trap.noscroll="open" class="relative w-full max-w-md rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
                <h2 id="confirm-title-{{ $attributes->get('id', 'x') }}" class="text-base font-semibold text-ink">{{ $title }}</h2>
                <div class="mt-2 text-sm text-muted">{{ $slot }}</div>
                <div class="mt-6 flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                    <x-ui.button :variant="$tone === 'danger' ? 'danger' : 'primary'"
                        x-on:click="open = false; $wire.{{ $attributes->get('action') }}">{{ $confirmLabel }}</x-ui.button>
                </div>
            </div>
        </div>
    </template>
</div>
