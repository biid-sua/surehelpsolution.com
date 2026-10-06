<div wire:poll.15s>
    <x-agent.company-bar :company="$company" active="messages" />

    <div class="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <x-ui.card :padding="false">
            <ul class="max-h-[65vh] divide-y divide-line overflow-y-auto">
                @forelse ($conversations as $c)
                    <li wire:key="ac-{{ $c->ulid }}">
                        <button type="button" wire:click="open('{{ $c->ulid }}')" @class(['block w-full px-4 py-3 text-left hover:bg-surface-2', 'bg-surface-2' => $current && $current->id === $c->id])>
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-full" style="background: {{ $c->channel->color() }}"></span>
                                <span class="truncate text-sm text-ink">{{ $c->displayName() }}</span>
                                @if ($c->needs_human)<x-ui.badge tone="warning" class="ml-auto">Needs a person</x-ui.badge>@endif
                            </div>
                            <p class="mt-1 text-xs text-subtle">{{ $c->channel->label() }} · {{ $c->last_message_at?->setTimezone($timezone)->diffForHumans() }}</p>
                        </button>
                    </li>
                @empty
                    <li class="p-6 text-center text-sm text-muted">No open conversations.</li>
                @endforelse
            </ul>
        </x-ui.card>

        @if ($current)
            <section class="flex min-h-[60vh] flex-col rounded-[var(--radius-card)] border border-line bg-surface shadow-[var(--shadow-card)]" aria-label="Conversation with {{ $current->displayName() }}">
                <div class="border-b border-line px-4 py-3">
                    <p class="font-semibold text-ink">{{ $current->displayName() }}</p>
                    <p class="text-xs text-subtle">{{ $current->channel->label() }}</p>
                </div>
                <ol class="flex-1 space-y-3 overflow-y-auto bg-surface-2/40 p-4">
                    @foreach ($messages as $m)
                        <li wire:key="am-{{ $m->ulid }}" @class(['flex', 'justify-end' => $m->direction === 'out'])>
                            <div @class(['max-w-[85%] rounded-2xl px-3.5 py-2 text-sm', 'bg-surface text-ink ring-1 ring-line' => $m->direction === 'in', 'bg-brand-600 text-white' => $m->direction === 'out'])>
                                <p class="mb-0.5 text-[11px] opacity-75">{{ $m->direction === 'in' ? $current->displayName() : ($m->isFromAi() ? 'AI assistant' : ($m->author->name ?? 'Team')) }} · {{ ($m->sent_at ?? $m->created_at)?->setTimezone($timezone)->format('D g:i A') }}</p>
                                <p class="whitespace-pre-line break-words">{{ $m->body }}</p>
                                @if ($m->status === 'failed')<p class="mt-1 text-xs text-red-200">Not sent: {{ $m->error }}</p>@endif
                            </div>
                        </li>
                    @endforeach
                </ol>
                @if ($canSend)
                    <form wire:submit="send" class="border-t border-line p-3">
                        @if ($window && ! $window['allowed'])<p class="mb-2 text-sm text-amber-300">{{ $window['reason'] }}</p>@endif
                        <label for="agent-reply" class="sr-only">Reply as {{ $company->name }}</label>
                        <textarea id="agent-reply" wire:model="reply" rows="3" class="sh-input" placeholder="Reply as {{ $company->name }}…" maxlength="5000"></textarea>
                        @error('reply') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        <div class="mt-2 text-right"><x-ui.button type="submit">Send</x-ui.button></div>
                    </form>
                @endif
            </section>
        @else
            <x-ui.card class="hidden items-center justify-center lg:flex"><x-ui.empty-state icon="chat" title="Choose a conversation" description="Messages that need a person are listed first." /></x-ui.card>
        @endif
    </div>
</div>
