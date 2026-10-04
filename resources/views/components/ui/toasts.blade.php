{{-- Global toast stack. Trigger from PHP: $this->dispatch('toast', type: 'success', message: '…'); from JS: $dispatch('toast', {...}). --}}
<div x-data="toasts" x-on:toast.window="push($event.detail)"
    class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6"
    aria-live="polite" aria-atomic="false">
    <template x-for="item in items" :key="item.id">
        <div x-transition.opacity
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-line-strong bg-surface-2 px-4 py-3 text-sm shadow-xl"
            :role="item.type === 'error' ? 'alert' : 'status'">
            <span class="mt-0.5 size-2 shrink-0 rounded-full"
                :class="{ 'bg-emerald-400': item.type === 'success', 'bg-red-400': item.type === 'error', 'bg-sky-400': item.type === 'info' }"></span>
            <p class="flex-1 text-ink" x-text="item.message"></p>
            <button type="button" class="text-subtle hover:text-ink" x-on:click="dismiss(item.id)" aria-label="Dismiss notification">
                <x-ui.icon name="x" class="size-4" />
            </button>
        </div>
    </template>
</div>

@if (session('toast'))
    <script>
        document.addEventListener('alpine:initialized', () => window.dispatchEvent(new CustomEvent('toast', { detail: @js(session('toast')) })));
    </script>
@endif
