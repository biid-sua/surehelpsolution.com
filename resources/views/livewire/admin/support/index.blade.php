<div>
    <x-ui.page-header title="Support" description="Requests from businesses. Urgent and longest-waiting first." />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Views">
            @foreach ($views as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}" wire:click="$set('view', '{{ $key }}')" @class([
                    'whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
                    'border-brand-500 text-ink' => $view === $key,
                    'border-transparent text-muted hover:text-ink' => $view !== $key,
                ])>{{ $label }}@if ($key === 'active' && $counts['active'] > 0) <span class="ml-1 rounded-full bg-amber-500/20 px-1.5 text-xs text-amber-300">{{ $counts['active'] }}</span>@endif</button>
            @endforeach
        </div>
        <label for="sup-search" class="sr-only">Search</label>
        <input id="sup-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input ml-auto w-64" placeholder="Subject, business or SR number…">
    </div>

    <div class="grid gap-6 xl:grid-cols-[26rem_minmax(0,1fr)]">
        <x-ui.card :padding="false">
            @if ($tickets->isEmpty())
                <x-ui.empty-state icon="chat" title="Nothing here" description="New requests from businesses appear in “Needs us”." />
            @else
                <ul class="divide-y divide-line" role="list">
                    @foreach ($tickets as $t)
                        <li wire:key="st-{{ $t->id }}">
                            <button type="button" wire:click="open('{{ $t->ulid }}')" @class(['block w-full px-5 py-3 text-left hover:bg-surface-2', 'bg-surface-2' => $current?->id === $t->id])>
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium text-ink">{{ $t->subject }}</span>
                                    <x-ui.badge :tone="$t->status->tone()">{{ $t->status->staffLabel() }}</x-ui.badge>
                                </span>
                                <span class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-subtle">
                                    <span>{{ $t->reference() }}</span><span>{{ $t->organization?->name }}</span>
                                    @if (in_array($t->priority, ['high', 'urgent'], true))<span class="font-medium text-red-300">{{ ucfirst($t->priority) }}</span>@endif
                                    <span>{{ $t->assignee?->name ?? 'Unassigned' }}</span>
                                    <span>{{ $t->last_reply_by_staff ? 'we replied' : 'they wrote' }} {{ $t->last_reply_at?->diffForHumans() }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :paginator="$tickets" />
            @endif
        </x-ui.card>

        <div>
            @if ($current)
                <x-ui.card>
                    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-ink">{{ $current->subject }}</h2>
                            <p class="mt-0.5 text-xs text-subtle">
                                {{ $current->reference() }} ·
                                @if ($current->organization)<a href="{{ route('admin.organizations.show', $current->organization) }}" class="underline">{{ $current->organization->name }}</a>@endif ·
                                {{ $current->categoryLabel() }} · {{ \App\Models\SupportTicket::PRIORITIES[$current->priority] ?? $current->priority }} ·
                                from {{ $current->openedBy?->name ?? 'a former team member' }}@if ($current->openedBy) ({{ $current->openedBy->email }})@endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label for="sup-assign" class="sr-only">Assigned to</label>
                            <select id="sup-assign" class="sh-input w-44 py-1.5 text-sm" x-on:change="$wire.assignTo(Number($event.target.value))">
                                <option value="0" @selected(! $current->assigned_to_user_id)>Unassigned</option>
                                @foreach ($staff as $s)<option value="{{ $s->id }}" @selected($current->assigned_to_user_id === $s->id)>{{ $s->name }}</option>@endforeach
                            </select>
                            @if ($current->assigned_to_user_id !== auth()->id())<x-ui.button size="sm" variant="secondary" wire:click="assignToMe">Take it</x-ui.button>@endif
                            <label for="sup-status" class="sr-only">Status</label>
                            <select id="sup-status" class="sh-input w-48 py-1.5 text-sm" x-on:change="$wire.setStatus($event.target.value)">
                                @foreach ($statuses as $s)<option value="{{ $s->value }}" @selected($current->status === $s)>{{ $s->staffLabel() }}</option>@endforeach
                            </select>
                        </div>
                    </div>

                    @include('partials.support.thread', ['downloadRoute' => 'admin.support.attachment'])

                    <form wire:submit="send" class="mt-5 space-y-3 border-t border-line pt-5">
                        <label for="sup-reply" class="sh-label">Reply to {{ $current->organization?->name }}</label>
                        <textarea id="sup-reply" wire:model="reply" rows="5" class="sh-input"></textarea>
                        @error('reply') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap items-center gap-3">
                            <input type="file" wire:model="replyFile" aria-label="Attachment" class="block text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-ink">
                            <label for="sup-after" class="text-sm text-muted">Then mark it</label>
                            <select id="sup-after" wire:model="replyStatus" class="sh-input w-52 py-1.5 text-sm">
                                @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->staffLabel() }}</option>@endforeach
                            </select>
                        </div>
                        @error('replyFile') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send,replyFile">Send reply</x-ui.button>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card><x-ui.empty-state icon="chat" title="Pick a request" /></x-ui.card>
            @endif
        </div>
    </div>
</div>
