<div wire:poll.10s>
    <x-ui.page-header title="Inbox" description="Every message from your website chat, Messenger and Instagram, in one place." />
    @include('livewire.client.inbox._tabs')

    <div class="grid gap-4 lg:grid-cols-[22rem_minmax(0,1fr)]">
        {{-- Conversation list --}}
        <x-ui.card :padding="false" :class="$current ? 'hidden lg:block' : ''">
            <div class="space-y-3 border-b border-line p-3">
                <div class="flex flex-wrap gap-1" role="tablist" aria-label="Show">
                    @foreach (['attention' => 'Needs you', 'ai' => 'AI handling', 'open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $value => $label)
                        <button type="button" role="tab" aria-selected="{{ $filter === $value ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $value }}')"
                            @class(['rounded-md px-2.5 py-1 text-xs font-medium', 'bg-surface-3 text-ink' => $filter === $value, 'text-muted hover:text-ink' => $filter !== $value])>
                            {{ $label }}@isset($counts[$value]) <span class="ml-0.5 text-subtle">{{ $counts[$value] }}</span>@endisset
                        </button>
                    @endforeach
                </div>
                <div class="flex gap-2">
                    <label for="inbox-search" class="sr-only">Search</label>
                    <input id="inbox-search" type="search" wire:model.live.debounce.400ms="search" class="sh-input py-1.5 text-sm" placeholder="Search name…">
                    <label for="inbox-channel" class="sr-only">Channel</label>
                    <select id="inbox-channel" wire:model.live="channel" class="sh-input w-32 py-1.5 text-sm">
                        <option value="">All</option>
                        @foreach ($channels as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                    </select>
                </div>
            </div>
            <ul class="max-h-[70vh] divide-y divide-line overflow-y-auto">
                @forelse ($conversations as $c)
                    <li wire:key="cv-{{ $c->ulid }}">
                        <button type="button" wire:click="open('{{ $c->ulid }}')" @class(['block w-full px-4 py-3 text-left hover:bg-surface-2', 'bg-surface-2' => $current && $current->id === $c->id])>
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $c->channel->color() }}" title="{{ $c->channel->label() }}"></span>
                                <span @class(['truncate text-sm', 'font-semibold text-ink' => $c->unread_count > 0, 'text-ink' => $c->unread_count === 0])>{{ $c->displayName() }}</span>
                                <span class="ml-auto shrink-0 text-xs text-subtle">{{ $c->last_message_at?->setTimezone($timezone)->shortRelativeDiffForHumans() }}</span>
                            </div>
                            <div class="mt-1 flex items-center gap-2 text-xs">
                                <span class="text-subtle">{{ $c->channel->label() }}</span>
                                @if ($c->status === 'closed')<x-ui.badge>Closed</x-ui.badge>
                                @elseif ($c->needs_human)<x-ui.badge tone="warning">Needs you</x-ui.badge>
                                @elseif (! $c->ai_paused)<x-ui.badge tone="info">AI</x-ui.badge>@endif
                                @if ($c->unread_count > 0)<span class="ml-auto rounded-full bg-brand-600 px-1.5 text-white">{{ $c->unread_count }}</span>@endif
                            </div>
                        </button>
                    </li>
                @empty
                    <li class="p-6 text-center text-sm text-muted">
                        {{ $filter === 'attention' ? 'Nothing needs you right now.' : 'No conversations here yet.' }}
                        <a href="{{ route('app.inbox.channels') }}" class="mt-2 block text-brand-300 underline">Add website chat or connect Messenger and Instagram</a>
                    </li>
                @endforelse
            </ul>
        </x-ui.card>

        {{-- Conversation --}}
        @if ($current)
            <section class="flex min-h-[70vh] flex-col rounded-[var(--radius-card)] border border-line bg-surface shadow-[var(--shadow-card)]" aria-label="Conversation with {{ $current->displayName() }}">
                <div class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3">
                    <button type="button" class="text-muted lg:hidden" wire:click="$set('selected', null)" aria-label="Back to conversations"><x-ui.icon name="arrow-left" class="size-4" /></button>
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-ink">{{ $current->displayName() }}</p>
                        <p class="text-xs text-subtle">
                            {{ $current->channel->label() }}@if ($current->contact_handle) · {{ $current->contact_handle }}@endif
                            @if ($current->customer) · <a class="text-brand-300 underline" href="{{ route('app.customers.show', $current->customer->ulid) }}">customer record</a>@endif
                        </p>
                    </div>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        @if ($canSend)
                            <label for="assign" class="sr-only">Assigned to</label>
                            <select id="assign" class="sh-input w-40 py-1 text-sm" wire:change="assign($event.target.value)">
                                <option value="">Unassigned</option>
                                @foreach ($members as $m)<option value="{{ $m->id }}" @selected($current->assigned_to_user_id === $m->id)>{{ $m->name }}</option>@endforeach
                            </select>
                            @if ($aiMode !== 'off')
                                @if ($current->ai_paused || $current->needs_human)
                                    <x-ui.button size="sm" variant="secondary" wire:click="setAi(true)" title="The assistant answers the next message">Hand back to AI</x-ui.button>
                                @else
                                    <x-ui.button size="sm" variant="secondary" wire:click="setAi(false)">Take over</x-ui.button>
                                @endif
                            @endif
                            @if ($current->status === 'open')
                                <x-ui.button size="sm" variant="ghost" wire:click="setStatus('closed')">Close</x-ui.button>
                            @else
                                <x-ui.button size="sm" variant="ghost" wire:click="setStatus('open')">Reopen</x-ui.button>
                            @endif
                        @endif
                    </div>
                </div>

                <ol class="flex-1 space-y-3 overflow-y-auto bg-surface-2/40 p-4" aria-label="Messages">
                    @foreach ($messages as $m)
                        <li wire:key="m-{{ $m->ulid }}" @class(['flex', 'justify-end' => $m->direction === 'out'])>
                            <div @class([
                                'max-w-[85%] rounded-2xl px-3.5 py-2 text-sm',
                                'bg-surface text-ink ring-1 ring-line' => $m->direction === 'in',
                                'bg-brand-600 text-white' => $m->direction === 'out' && ! $m->is_note && $m->status !== 'draft' && ! $m->isFromAi(),
                                'bg-indigo-500/20 text-ink ring-1 ring-indigo-400/40' => $m->direction === 'out' && $m->isFromAi() && $m->status !== 'draft',
                                'border border-dashed border-amber-400/60 bg-amber-500/10 text-ink' => $m->is_note,
                                'border border-dashed border-indigo-400/70 bg-surface text-ink' => $m->status === 'draft',
                            ])>
                                <p class="mb-0.5 text-[11px] opacity-75">
                                    @if ($m->is_note) Team note · {{ $m->author->name ?? 'Team' }}
                                    @elseif ($m->status === 'draft') Suggested by the AI: not sent
                                    @elseif ($m->isFromAi()) AI assistant
                                    @elseif ($m->direction === 'out') {{ $m->author->name ?? 'Sent from '.$current->channel->label() }}
                                    @else {{ $current->displayName() }} @endif
                                    · {{ ($m->sent_at ?? $m->created_at)?->setTimezone($timezone)->format('D g:i A') }}
                                </p>
                                @if ($m->body)<p class="whitespace-pre-line break-words">{{ $m->body }}</p>@endif
                                @foreach ((array) $m->attachments as $a)
                                    <a href="{{ $a['url'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 block text-xs underline">{{ ucfirst($a['type'] ?? 'file') }} attachment</a>
                                @endforeach
                                @if ($m->status === 'failed')<p class="mt-1 text-xs text-red-200">Not sent: {{ $m->error }}</p>@endif

                                @if ($m->isFromAi() && $m->aiRun?->tool_calls)
                                    <details class="mt-1 text-xs opacity-80"><summary class="cursor-pointer">What the AI did</summary>
                                        <ul class="mt-1 list-disc pl-4">@foreach ($m->aiRun->tool_calls as $call)<li>{{ str_replace('_', ' ', $call['name']) }}: {{ $call['summary'] }}</li>@endforeach</ul>
                                    </details>
                                @endif

                                @if ($m->status === 'draft' && $canSend)
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <x-ui.button size="sm" wire:click="sendDraft('{{ $m->ulid }}')">Send</x-ui.button>
                                        <x-ui.button size="sm" variant="secondary" wire:click="editDraft('{{ $m->ulid }}')">Edit</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" wire:click="discardDraft('{{ $m->ulid }}')">Discard</x-ui.button>
                                    </div>
                                @endif

                                @if ($m->isFromAi() && in_array($m->status, ['sent', 'draft'], true))
                                    @php($mine = $m->feedback->first())
                                    <div class="mt-2 flex items-center gap-2 text-xs">
                                        <span class="opacity-75">Was this right?</span>
                                        <button type="button" wire:click="rate('{{ $m->ulid }}', 'helpful')" @class(['rounded px-1.5 py-0.5 ring-1 ring-current', 'bg-emerald-500/30' => $mine?->rating === 'helpful']) aria-label="Helpful">👍</button>
                                        <button type="button" wire:click="rate('{{ $m->ulid }}', 'unhelpful')" @class(['rounded px-1.5 py-0.5 ring-1 ring-current', 'bg-red-500/30' => $mine?->rating === 'unhelpful']) aria-label="Not helpful">👎</button>
                                    </div>
                                    @if ($correcting === $m->ulid)
                                        <form wire:submit="saveCorrection('{{ $m->ulid }}')" class="mt-2 space-y-2">
                                            <label for="fix-{{ $m->ulid }}" class="block text-xs">What should it have said or done? It becomes a guideline once an owner approves it.</label>
                                            <textarea id="fix-{{ $m->ulid }}" wire:model="correction.{{ $m->ulid }}" rows="2" class="sh-input text-sm" maxlength="1000" placeholder="e.g. We don't do same-day boiler installs; offer the next weekday."></textarea>
                                            @error('correction.'.$m->ulid) <p class="text-xs text-danger">{{ $message }}</p> @enderror
                                            <x-ui.button type="submit" size="sm" variant="secondary">Save</x-ui.button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if ($canSend)
                    <form wire:submit="send" class="border-t border-line p-3">
                        @if ($window && ! $window['allowed'] && ! $asNote)
                            <p class="mb-2 text-sm text-amber-300">{{ $window['reason'] }} You can still add a team note.</p>
                        @elseif ($window && $window['human_agent_tag'] && ! $asNote)
                            <p class="mb-2 text-xs text-muted">It's been more than 24 hours: your reply is sent as a personal reply from your team (Meta's human-agent rule).</p>
                        @endif
                        <label for="reply" class="sr-only">{{ $asNote ? 'Team note' : 'Reply' }}</label>
                        <textarea id="reply" wire:model="reply" rows="3" @class(['sh-input', 'border-amber-400/60' => $asNote]) placeholder="{{ $asNote ? 'A note only your team sees…' : 'Write a reply…' }}" maxlength="5000"></textarea>
                        @error('reply') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <label class="flex items-center gap-2 text-sm text-muted">
                                <input type="checkbox" wire:model.live="asNote" class="size-4 rounded border-line-strong bg-surface-2 text-amber-500"> Team note
                            </label>
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send">{{ $asNote ? 'Add note' : 'Send' }}</x-ui.button>
                        </div>
                    </form>
                @endif
            </section>
        @else
            <x-ui.card class="hidden items-center justify-center lg:flex">
                <x-ui.empty-state icon="chat" title="Choose a conversation" description="Messages that need a person are under “Needs you”. Your AI assistant's conversations are under “AI handling”." />
            </x-ui.card>
        @endif
    </div>
</div>
