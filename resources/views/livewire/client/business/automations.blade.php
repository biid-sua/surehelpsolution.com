<div>
    <x-ui.page-header title="Business" description="Automations: when something happens, run a flow of steps: email or text the customer, wait, check whether they replied, tag them, create a task or tell your team." />
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
                                    <p class="text-xs text-subtle">When {{ mb_strtolower(\App\Models\Automation::TRIGGERS[$a->trigger] ?? $a->trigger) }}, {{ $a->delayLabel() }}: @if ($a->isFlow()){{ $a->stepCount() }} steps, starting with {{ mb_strtolower(\App\Models\Automation::ACTIONS[$a->action] ?? $a->action) }}@else{{ mb_strtolower(\App\Models\Automation::ACTIONS[$a->action] ?? $a->action) }}@endif @if ($a->rules()) (if {{ count($a->rules()) }} {{ \Illuminate\Support\Str::plural('condition', count($a->rules())) }} hold)@endif.
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
                                    @foreach ($triggerGroups as $group => $list)
                                        <optgroup label="{{ $group }}">
                                            @foreach ($list as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <span class="sh-label">{{ ($form['trigger'] ?? '') === 'appointment_upcoming' ? 'How long before it starts' : 'Wait' }}</span>
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
                        @if (($form['trigger'] ?? '') === 'customer_inactive')
                            <div>
                                <label for="a-inactive" class="sh-label">No visit for (days)</label>
                                <input id="a-inactive" type="number" min="7" max="1095" wire:model="form.inactive_days" class="sh-input w-32">
                                <p class="mt-1 text-xs text-subtle">Customers who had a completed appointment, then nothing booked for this long. Each customer is reached once.</p>
                                @error('form.inactive_days') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                        @endif
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
                        <fieldset class="rounded-xl border border-line p-3">
                            <legend class="px-1 text-sm font-medium text-ink">Only if (optional)</legend>
                            @include('livewire.client.business._automation-rules', ['rules' => $form['rules'] ?? [], 'key' => 'form.rules', 'path' => '', 'branch' => false])
                            <p class="mt-1 text-xs text-subtle">Checked when it happens and again before each action.</p>
                        </fieldset>
                        <fieldset>
                            <legend class="sh-label">Then <span class="font-normal text-subtle">({{ $stepCount }} of {{ $maxSteps }} steps)</span></legend>
                            @include('livewire.client.business._automation-steps', ['steps' => $form['steps'] ?? [], 'list' => '', 'depth' => 1])
                            @error('form.steps') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            <p class="mt-2 text-xs text-subtle">"A reply from them" and "A new booking" in an If step look at what happened since the flow started for this customer.</p>
                        </fieldset>

                        <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model="form.is_active" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> On</label>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="submit">Save</x-ui.button>
                            <x-ui.button variant="secondary" wire:click="testRun" wire:loading.attr="disabled" wire:target="testRun">Test run</x-ui.button>
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

                        <div class="rounded-xl border border-line p-3" aria-live="polite">
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="min-w-0 flex-1">
                                    <label for="a-test-customer" class="sh-label">Test run with</label>
                                    <select id="a-test-customer" wire:model="testCustomer" class="sh-input">
                                        <option value="">The most recent one</option>
                                        @foreach ($testCustomers as $c)<option value="{{ $c->id }}">{{ $c->fullName() }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-subtle">Shows what would happen, step by step, using a real record. Nothing is sent or changed.</p>
                            @if ($test)
                                @if ($test['empty'])
                                    <p class="mt-3 text-sm text-muted">{{ $test['empty'] }}</p>
                                @else
                                    <p class="mt-3 text-sm font-medium text-ink">Tried on {{ $test['about'] }}</p>
                                    <ol class="mt-2 space-y-2 border-l border-line pl-4" role="list">
                                        @foreach ($test['lines'] as $line)
                                            <li class="text-sm">
                                                <span class="font-medium {{ ['done' => 'text-ink', 'skipped' => 'text-warning', 'cancelled' => 'text-danger', 'waiting' => 'text-muted'][$line['status']] ?? 'text-ink' }}">{{ $line['step'] }}</span>
                                                <span class="text-muted">· {{ $line['text'] }}</span>
                                            </li>
                                        @endforeach
                                    </ol>
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
                                    @if (count($run->log ?? []) > 1)
                                        <details class="mt-1 text-xs">
                                            <summary class="cursor-pointer text-brand-300">{{ count($run->log) }} steps</summary>
                                            <ol class="mt-1 space-y-0.5 text-subtle">
                                                @foreach ($run->log as $entry)<li>{{ $entry['step'] }} · {{ $entry['text'] }}</li>@endforeach
                                            </ol>
                                        </details>
                                    @endif
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
                            <p class="mt-0.5 text-xs text-subtle">{{ \App\Models\Automation::TRIGGERS[$r['trigger']] }}, then {{ mb_strtolower(\App\Models\Automation::ACTIONS[$r['action']]) }}@if (! empty($r['flow'])), wait, and follow up if needed <x-ui.badge tone="info">Flow</x-ui.badge>@endif.</p>
                            <x-ui.button size="sm" variant="secondary" class="mt-3" wire:click="start('{{ $key }}')">Use this</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
</div>
