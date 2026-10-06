<div>
    <x-ui.page-header title="Inbox" description="Your AI assistant answers messages like a trained receptionist: from your own information, booking into your real calendar, and passing anything sensitive to your team." />
    @include('livewire.client.inbox._tabs')

    @unless ($configured)
        <x-ui.alert tone="info" class="mb-6" title="Coming soon">We're finishing the AI setup. You can prepare the settings and guidelines now; nothing is sent to the AI until it's switched on.</x-ui.alert>
    @endunless

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <form wire:submit="save" class="space-y-6">
            <x-ui.card title="Settings">
                <fieldset @disabled(! $canManage) class="space-y-5">
                    <label class="flex items-center gap-3 text-sm font-medium text-ink">
                        <input type="checkbox" wire:model.live="form.is_enabled" class="size-5 rounded border-line-strong bg-surface-2 text-brand-500">
                        Use the AI assistant
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="ai-name" class="sh-label">Its name</label>
                            <input id="ai-name" type="text" wire:model="form.name" class="sh-input" maxlength="40">
                            <p class="mt-1 text-xs text-subtle">Customers are told it's an AI assistant.</p>
                            @error('form.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="ai-tone" class="sh-label">Tone</label>
                            <select id="ai-tone" wire:model="form.tone" class="sh-input">
                                @foreach ($tones as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <p class="sh-label">On each channel</p>
                        <div class="divide-y divide-line rounded-xl ring-1 ring-line">
                            @foreach ($channels as $c)
                                <div class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="mode-{{ $c->value }}">
                                    <span class="size-2.5 rounded-full" style="background: {{ $c->color() }}"></span>
                                    <span class="flex-1 text-sm text-ink">{{ $c->label() }}</span>
                                    <label for="mode-{{ $c->value }}" class="sr-only">Mode for {{ $c->label() }}</label>
                                    <select id="mode-{{ $c->value }}" wire:model="form.modes.{{ $c->value }}" class="sh-input w-56 py-1.5 text-sm">
                                        @foreach ($modes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-subtle"><strong>Suggest</strong>: it drafts each reply and a person sends it. <strong>Reply automatically</strong>: it answers by itself and hands over to your team when it should. On Messenger and Instagram it only replies within 24 hours of the customer's message.</p>
                    </div>

                    <div class="space-y-2">
                        <label class="flex items-center gap-3 text-sm text-ink">
                            <input type="checkbox" wire:model.live="form.can_book" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                            It may book appointments (within your hours, rules and free times)
                        </label>
                        @if ($form['can_book'])
                            <label class="ml-7 flex items-center gap-3 text-sm text-ink">
                                <input type="checkbox" wire:model="form.bookings_need_confirmation" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                Its bookings wait for my team to confirm (pending)
                            </label>
                        @endif
                    </div>

                    <div>
                        <label for="ai-handover" class="sh-label">What it says when it passes a conversation to your team</label>
                        <textarea id="ai-handover" wire:model="form.handover_message" rows="2" class="sh-input" maxlength="500" placeholder="I want to make sure you get the right answer, so I've passed this to our team. Someone will reply here as soon as possible."></textarea>
                    </div>

                    <div>
                        <label for="ai-instructions" class="sh-label">Anything else it should know or do</label>
                        <textarea id="ai-instructions" wire:model="form.instructions" rows="4" class="sh-input" maxlength="4000" placeholder="e.g. Always ask whether the customer has pets before booking a cleaning. Mention our 10-year warranty on installs."></textarea>
                        <p class="mt-1 text-xs text-subtle">It also uses your business profile, services, hours, knowledge base and rules.</p>
                    </div>
                </fieldset>
                @if ($canManage)
                    <div class="mt-5 flex flex-wrap gap-2">
                        <x-ui.button type="submit">Save</x-ui.button>
                        @if ($form['is_enabled'])
                            <x-ui.button variant="danger" wire:click="stopAll" wire:confirm="Turn the AI assistant off everywhere now?">Stop the AI now</x-ui.button>
                        @endif
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="Guidelines" description="Things your team taught the assistant. Approved guidelines are followed in every reply.">
                @if ($guidelines->isEmpty())
                    <p class="text-sm text-muted">None yet. When an AI reply isn't right, press 👎 on it in the inbox and say what it should have said. It appears here to approve.</p>
                @else
                    <ul class="space-y-3">
                        @foreach ($guidelines as $g)
                            <li wire:key="g-{{ $g->ulid }}" @class(['rounded-xl p-3 ring-1', 'bg-amber-500/10 ring-amber-400/40' => $g->status === 'draft', 'ring-line' => $g->status === 'active'])>
                                <div class="mb-2 flex items-center gap-2 text-xs text-subtle">
                                    <x-ui.badge :tone="$g->status === 'draft' ? 'warning' : 'success'">{{ $g->status === 'draft' ? 'To approve' : 'Active' }}</x-ui.badge>
                                    from {{ $g->creator->name ?? 'your team' }}
                                </div>
                                @if ($canManage)
                                    <label for="gt-{{ $g->ulid }}" class="sr-only">Guideline</label>
                                    <textarea id="gt-{{ $g->ulid }}" wire:model="guidelineText.{{ $g->ulid }}" rows="2" class="sh-input text-sm" maxlength="1000"></textarea>
                                    @error('guidelineText.'.$g->ulid) <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                    <div class="mt-2 flex gap-2">
                                        <x-ui.button size="sm" wire:click="approveGuideline('{{ $g->ulid }}')">{{ $g->status === 'draft' ? 'Approve' : 'Save' }}</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" wire:click="archiveGuideline('{{ $g->ulid }}')">{{ $g->status === 'draft' ? 'Dismiss' : 'Remove' }}</x-ui.button>
                                    </div>
                                @else
                                    <p class="text-sm text-ink">{{ $g->text }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManage)
                    <div class="mt-4 flex gap-2">
                        <label for="new-guideline" class="sr-only">New guideline</label>
                        <input id="new-guideline" type="text" wire:model="newGuideline" class="sh-input" placeholder="Add a guideline…" maxlength="1000">
                        <x-ui.button variant="secondary" wire:click="addGuideline">Add</x-ui.button>
                    </div>
                    @error('newGuideline') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                @endif
            </x-ui.card>
        </form>

        <aside class="space-y-4" aria-label="Results">
            <x-ui.card title="Last 30 days">
                <dl class="grid grid-cols-2 gap-4">
                    <div><dt class="text-xs text-subtle">Replied by itself</dt><dd class="text-2xl font-semibold text-ink">{{ number_format($stats['replied']) }}</dd></div>
                    <div><dt class="text-xs text-subtle">Replies suggested</dt><dd class="text-2xl font-semibold text-ink">{{ number_format($stats['drafted']) }}</dd></div>
                    <div><dt class="text-xs text-subtle">Passed to your team</dt><dd class="text-2xl font-semibold text-ink">{{ number_format($stats['handed_over']) }}</dd></div>
                    <div><dt class="text-xs text-subtle">Appointments booked</dt><dd class="text-2xl font-semibold text-ink">{{ number_format($stats['booked']) }}</dd></div>
                    <div class="col-span-2"><dt class="text-xs text-subtle">Rated helpful by your team</dt><dd class="text-2xl font-semibold text-ink">{{ $stats['helpful'] !== null ? $stats['helpful'].'%' : '—' }} <span class="text-sm font-normal text-subtle">({{ $stats['rated'] }} ratings)</span></dd></div>
                </dl>
            </x-ui.card>
            <x-ui.card title="How it stays safe">
                <ul class="list-disc space-y-1 pl-5 text-sm text-muted">
                    <li>It only uses your information and never invents prices or promises.</li>
                    <li>It books only free times, within your hours and rules.</li>
                    <li>Complaints, refunds, emergencies and anything it's unsure about go to your team.</li>
                    <li>When someone on your team replies, it steps back in that conversation.</li>
                    <li>Every reply and action is shown in the conversation.</li>
                </ul>
            </x-ui.card>
        </aside>
    </div>
</div>
