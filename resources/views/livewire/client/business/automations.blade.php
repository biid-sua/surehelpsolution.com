<div>
    <x-ui.page-header title="Business" description="Automations: when something happens, wait if you like, then email the customer, create a task or tell your team." />
    @include('livewire.client.business._tabs')

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="space-y-6">
            <x-ui.card :padding="false" title="Your automations">
                @if ($canManage)
                    <x-slot:actions><x-ui.button size="sm" wire:click="start">New automation</x-ui.button></x-slot:actions>
                @endif
                @if ($automations->isEmpty())
                    <x-ui.empty-state icon="refresh" title="No automations yet" description="Start from a recipe on the right, or make your own." />
                @else
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($automations as $a)
                            <li class="flex flex-wrap items-center gap-3 px-5 py-4" wire:key="au-{{ $a->id }}">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-ink">{{ $a->name }}</p>
                                    <p class="text-xs text-subtle">When {{ mb_strtolower(\App\Models\Automation::TRIGGERS[$a->trigger] ?? $a->trigger) }}, {{ $a->delayLabel() }}: {{ mb_strtolower(\App\Models\Automation::ACTIONS[$a->action] ?? $a->action) }}.
                                        {{ $a->done_count }} done{{ $a->pending_count ? ', '.$a->pending_count.' waiting' : '' }}.</p>
                                </div>
                                @if ($canManage)
                                    <label class="flex items-center gap-2 text-sm text-muted">
                                        <input type="checkbox" @checked($a->is_active) wire:click="toggle({{ $a->id }})" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> On
                                    </label>
                                    <x-ui.button size="sm" variant="secondary" wire:click="edit('{{ $a->ulid }}')">Edit</x-ui.button>
                                @else
                                    <x-ui.badge :tone="$a->is_active ? 'success' : 'neutral'">{{ $a->is_active ? 'On' : 'Off' }}</x-ui.badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if ($editing && $canManage)
                <x-ui.card :title="$editing === 'new' ? 'New automation' : 'Edit automation'">
                    <form wire:submit="save" class="space-y-4">
                        <div>
                            <label for="a-name" class="sh-label">Name</label>
                            <input id="a-name" type="text" wire:model="form.name" class="sh-input" maxlength="120">
                            @error('form.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="a-trigger" class="sh-label">When</label>
                                <select id="a-trigger" wire:model.live="form.trigger" class="sh-input">
                                    @foreach ($triggers as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <span class="sh-label">Wait</span>
                                <div class="flex gap-2">
                                    <label for="a-delay" class="sr-only">Wait for</label>
                                    <input id="a-delay" type="number" min="0" wire:model="form.delay" class="sh-input w-24">
                                    <label for="a-unit" class="sr-only">Unit</label>
                                    <select id="a-unit" wire:model="form.unit" class="sh-input">
                                        @foreach ($units as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                                    </select>
                                </div>
                                @error('form.delay') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        @if (str_starts_with($form['trigger'] ?? '', 'appointment_') && $services->isNotEmpty())
                            <fieldset>
                                <legend class="sh-label">Only for these services (none ticked = all)</legend>
                                <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-ink">
                                    @foreach ($services as $s)
                                        <label class="flex items-center gap-2"><input type="checkbox" value="{{ $s->id }}" wire:model="form.service_ids" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> {{ $s->name }}</label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                        <div>
                            <label for="a-action" class="sh-label">Then</label>
                            <select id="a-action" wire:model.live="form.action" class="sh-input">
                                @foreach ($actions as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>

                        @if (($form['action'] ?? '') === 'send_email')
                            <div>
                                <label for="a-subject" class="sh-label">Email subject</label>
                                <input id="a-subject" type="text" wire:model="form.subject" class="sh-input">
                                @error('form.subject') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="a-body" class="sh-label">Email</label>
                                <textarea id="a-body" wire:model="form.body" rows="8" class="sh-input font-mono text-sm"></textarea>
                                <p class="mt-1 text-xs text-subtle">You can use: @foreach ($placeholders as $p)<code class="mr-1 rounded bg-surface-2 px-1">{{ '{'.$p.'}' }}</code>@endforeach</p>
                                @error('form.body') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="a-review" class="sh-label">Review link (for {review_link})</label>
                                <input id="a-review" type="url" wire:model="form.review_url" class="sh-input" placeholder="https://g.page/r/…/review">
                                @error('form.review_url') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                            <label class="flex items-start gap-3 rounded-lg bg-surface-2 p-3 text-sm">
                                <input type="checkbox" wire:model="form.consent_only" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                <span><span class="font-medium text-ink">Only customers who agreed to emails</span>
                                    <span class="block text-xs text-subtle">Recommended for thank-you, review and marketing emails. Others are skipped and shown in the history.</span></span>
                            </label>
                        @elseif (($form['action'] ?? '') === 'create_task')
                            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
                                <div>
                                    <label for="a-title" class="sh-label">Task</label>
                                    <input id="a-title" type="text" wire:model="form.title" class="sh-input">
                                    @error('form.title') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="a-due" class="sh-label">Due after (hours)</label>
                                    <input id="a-due" type="number" min="0" wire:model="form.due_hours" class="sh-input">
                                    @error('form.due_hours') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @else
                            <div>
                                <label for="a-msg" class="sh-label">Message to your team</label>
                                <input id="a-msg" type="text" wire:model="form.message" class="sh-input" maxlength="500">
                                @error('form.message') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model="form.is_active" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> On</label>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="submit">Save</x-ui.button>
                            <x-ui.button variant="ghost" wire:click="$set('editing', null)">Cancel</x-ui.button>
                            @if ($editing !== 'new')
                                @php($editingId = $automations->firstWhere('ulid', $editing)?->id)
                                @if ($editingId)
                                    <x-ui.confirm id="del-auto" title="Delete this automation?" confirm-label="Delete" :action="'delete('.$editingId.')'">
                                        <x-slot:trigger><x-ui.button variant="ghost" class="text-danger">Delete</x-ui.button></x-slot:trigger>
                                        Steps still waiting are dropped. The history goes too.
                                    </x-ui.confirm>
                                @endif
                            @endif
                        </div>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card :padding="false" title="Recent runs" :description="$upcoming ? $upcoming.' waiting to run.' : null">
                @if ($history->isEmpty())
                    <p class="px-5 py-4 text-sm text-muted">Nothing has run yet.</p>
                @else
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($history as $run)
                            <li class="flex items-start gap-3 px-5 py-3 text-sm" wire:key="run-{{ $run->id }}">
                                <x-ui.badge :tone="['done' => 'success', 'skipped' => 'neutral', 'cancelled' => 'neutral', 'failed' => 'danger'][$run->status] ?? 'neutral'">{{ ucfirst($run->status) }}</x-ui.badge>
                                <div class="min-w-0 flex-1">
                                    <p class="text-ink">{{ $run->automation?->name }}</p>
                                    <p class="text-xs text-subtle">{{ $run->result }}</p>
                                </div>
                                <span class="whitespace-nowrap text-xs text-subtle">{{ $run->ran_at?->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        @if ($canManage)
            <x-ui.card title="Recipes" description="Ready-made automations. Pick one, adjust it, save.">
                <ul class="space-y-3" role="list">
                    @foreach ($recipes as $key => $r)
                        <li class="rounded-xl border border-line p-4">
                            <p class="font-medium text-ink">{{ $r['name'] }}</p>
                            <p class="mt-0.5 text-xs text-subtle">{{ \App\Models\Automation::TRIGGERS[$r['trigger']] }}, then {{ mb_strtolower(\App\Models\Automation::ACTIONS[$r['action']]) }}.</p>
                            <x-ui.button size="sm" variant="secondary" class="mt-3" wire:click="start('{{ $key }}')">Use this</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
</div>
