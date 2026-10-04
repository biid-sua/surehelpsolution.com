<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" wire:poll.30s.visible>
    <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="true"
        class="relative rounded-lg p-2 text-muted hover:bg-surface-2 hover:text-ink"
        aria-label="Notifications{{ $unread ? ', '.$unread.' unread' : '' }}">
        <x-ui.icon name="bell" class="size-5" />
        @if ($unread)
            <span class="absolute right-1 top-1 grid min-w-4 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-4 text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.origin.top.right
        class="absolute right-0 z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-line-strong bg-surface shadow-2xl">
        <div class="flex items-center justify-between border-b border-line px-4 py-3">
            <p class="text-sm font-semibold text-ink">Notifications</p>
            @if ($unread)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-brand-300 hover:text-brand-400">Mark all as read</button>
            @endif
        </div>

        @forelse ($items as $item)
            <button type="button" wire:click="open('{{ $item->id }}')" wire:key="n-{{ $item->id }}"
                class="flex w-full gap-3 border-b border-line px-4 py-3 text-left last:border-0 hover:bg-surface-2">
                <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-brand-400' => ! $item->read_at, 'bg-transparent' => $item->read_at])></span>
                <span class="min-w-0 flex-1">
                    <span @class(['block truncate text-sm', 'font-semibold text-ink' => ! $item->read_at, 'text-muted' => $item->read_at])>{{ $item->data['title'] ?? 'Notification' }}</span>
                    <span class="mt-0.5 block line-clamp-2 text-xs text-subtle">{{ $item->data['body'] ?? '' }}</span>
                    <span class="mt-1 block text-[11px] text-subtle">{{ $item->created_at->diffForHumans() }}</span>
                </span>
            </button>
        @empty
            <x-ui.empty-state icon="inbox" title="You're all caught up" description="We'll let you know here when something needs your attention." class="py-8" />
        @endforelse
    </div>
</div>
