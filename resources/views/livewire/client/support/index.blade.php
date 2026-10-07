<div>
    <x-ui.page-header title="Support" description="Ask SureHelp for help or a change. We reply here and by email.">
        @if ($canCreate)
            <x-slot:actions>
                <x-ui.button variant="secondary" wire:click="startNew('script_change')">Request a script change</x-ui.button>
                <x-ui.button icon="chat" wire:click="startNew">New request</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
        <x-ui.card :padding="false" title="Your requests">
            @if ($tickets->isEmpty())
                <x-ui.empty-state icon="chat" title="No requests yet" description="Questions, changes to what our agents say, or something not working: send it here." />
            @else
                <ul class="divide-y divide-line" role="list">
                    @foreach ($tickets as $t)
                        <li wire:key="t-{{ $t->id }}">
                            <button type="button" wire:click="$set('selected', '{{ $t->ulid }}')" @class(['block w-full px-5 py-3 text-left hover:bg-surface-2', 'bg-surface-2' => $current?->id === $t->id])>
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium text-ink">{{ $t->subject }}</span>
                                    <x-ui.badge :tone="$t->status->tone()">{{ $t->status->label() }}</x-ui.badge>
                                </span>
                                <span class="mt-0.5 block text-xs text-subtle">{{ $t->reference() }} · {{ $t->categoryLabel() }} · {{ $t->last_reply_at?->diffForHumans() }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :paginator="$tickets" />
            @endif
        </x-ui.card>

        <div>
            @if ($creating)
                <x-ui.card title="New request">
                    <form wire:submit="create" class="space-y-4">
                        <div>
                            <label for="s-subject" class="sh-label">Subject</label>
                            <input id="s-subject" type="text" wire:model="form.subject" class="sh-input" maxlength="200">
                            @error('form.subject') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="s-category" class="sh-label">About</label>
                                <select id="s-category" wire:model="form.category" class="sh-input">
                                    @foreach ($categories as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="s-priority" class="sh-label">How urgent?</label>
                                <select id="s-priority" wire:model="form.priority" class="sh-input">
                                    @foreach ($priorities as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="s-body" class="sh-label">Message</label>
                            <textarea id="s-body" wire:model="form.body" rows="7" class="sh-input"></textarea>
                            @error('form.body') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="s-file" class="sh-label">Attachment (optional)</label>
                            <input id="s-file" type="file" wire:model="file" class="block text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-ink">
                            <p class="mt-1 text-xs text-subtle">Up to 10 MB: PDF, images, text, Word or Excel.</p>
                            @error('file') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="create,file">Send</x-ui.button>
                            <x-ui.button variant="ghost" wire:click="$set('creating', false)">Cancel</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @elseif ($current)
                <x-ui.card>
                    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-ink">{{ $current->subject }}</h2>
                            <p class="mt-0.5 text-xs text-subtle">{{ $current->reference() }} · {{ $current->categoryLabel() }} · {{ \App\Models\SupportTicket::PRIORITIES[$current->priority] ?? $current->priority }} priority · opened by {{ $current->openedBy?->name ?? 'a former team member' }}</p>
                        </div>
                        <x-ui.badge :tone="$current->status->tone()">{{ $current->status->label() }}</x-ui.badge>
                    </div>

                    @include('partials.support.thread', ['downloadRoute' => 'app.support.attachment'])

                    @if ($canCreate)
                        @if ($current->status === \App\Enums\SupportTicketStatus::Closed)
                            <p class="mt-5 text-sm text-muted">This request is closed. <button type="button" wire:click="startNew" class="font-medium text-brand-300 underline">Open a new one</button> if you need more help.</p>
                        @else
                            <form wire:submit="send" class="mt-5 space-y-3 border-t border-line pt-5">
                                <label for="s-reply" class="sh-label">Reply</label>
                                <textarea id="s-reply" wire:model="reply" rows="4" class="sh-input"></textarea>
                                @error('reply') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                                <input type="file" wire:model="replyFile" aria-label="Attachment" class="block text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-ink">
                                @error('replyFile') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send,replyFile">Send reply</x-ui.button>
                                    <x-ui.confirm id="close-ticket" title="Close this request?" confirm-label="Close it" action="close()">
                                        <x-slot:trigger><x-ui.button variant="ghost">It's sorted, close it</x-ui.button></x-slot:trigger>
                                        You can open a new request any time.
                                    </x-ui.confirm>
                                </div>
                            </form>
                        @endif
                    @endif
                </x-ui.card>
            @else
                <x-ui.card><x-ui.empty-state icon="chat" title="Pick a request" description="Or start a new one. Changes to what our agents say go fastest with &quot;Request a script change&quot;." /></x-ui.card>
            @endif
        </div>
    </div>
</div>
