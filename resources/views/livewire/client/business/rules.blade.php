<div>
    <x-ui.page-header title="Business" description="Rules our agents and booking system follow for you. Most are checked automatically, so they can't be forgotten." />
    @include('livewire.client.business._tabs')

    <x-ui.card class="mb-6" title="Approve our agents' bookings" description="On: appointments our agents book arrive as &quot;Needs confirming&quot; and the customer gets no confirmation until you confirm. Off: they're confirmed straight away.">
        <label class="flex items-center gap-3 text-sm text-ink">
            <input type="checkbox" @checked($approveAgentBookings) @disabled(! $canManage) wire:click="toggleApproval" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
            I approve bookings made by SureHelp agents
        </label>
    </x-ui.card>

    <x-ui.card :padding="false">
        @if ($rules->isEmpty())
            <x-ui.empty-state icon="shield" title="No rules yet"
                description="For example: don't book emergency visits after 5 PM, always ask for the property address, escalate complaints." />
        @else
            <ul class="divide-y divide-line" role="list">
                @foreach ($rules as $rule)
                    <li wire:key="rule-{{ $rule->id }}" @class(['flex flex-wrap items-center gap-3 px-5 py-4', 'opacity-60' => ! $rule->is_active])>
                        <div class="min-w-0 flex-1">
                            <p class="text-ink">{{ $rule->sentence($serviceNames) }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-subtle">
                                {{ $rule->type->label() }}
                                <x-ui.badge :tone="$rule->type->isEnforced() ? 'success' : 'info'">{{ $rule->type->isEnforced() ? 'Checked automatically' : 'Shown to agents' }}</x-ui.badge>
                                @unless ($rule->is_active)<x-ui.badge>Off</x-ui.badge>@endunless
                            </p>
                        </div>
                        @if ($canManage)
                            <div class="flex shrink-0 gap-1">
                                <x-ui.button variant="ghost" size="sm" wire:click="toggle({{ $rule->id }})">{{ $rule->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
                                <x-ui.button variant="ghost" size="sm" wire:click="delete({{ $rule->id }})" wire:confirm="Delete this rule?">Delete</x-ui.button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canManage)
            <form wire:submit="add" class="space-y-4 border-t border-line px-5 py-5">
                <div class="grid gap-4 sm:grid-cols-[18rem_1fr] sm:items-start">
                    <div>
                        <label for="rule-type" class="sh-label">Add a rule</label>
                        <select id="rule-type" wire:model.live="type" class="sh-input">
                            @foreach ($types as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                        </select>
                        <p class="mt-1 text-xs text-subtle">{{ \App\Enums\BusinessRuleType::from($type)->hint() }}</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @switch($type)
                            @case('instruction')
                                <div class="sm:col-span-2">
                                    <label for="r-text" class="sh-label">Instruction</label>
                                    <textarea id="r-text" wire:model="config.text" rows="2" class="sh-input" maxlength="500" placeholder="Never give final prices for custom jobs; offer a free estimate."></textarea>
                                    @error('config.text') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                @break
                            @case('booking_cutoff')
                                <div>
                                    <label for="r-time" class="sh-label">No bookings starting at or after</label>
                                    <input id="r-time" type="time" wire:model="config.time" class="sh-input">
                                    @error('config.time') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="r-service" class="sh-label">For</label>
                                    <select id="r-service" wire:model="config.service_id" class="sh-input">
                                        <option value="">All services</option>
                                        @foreach ($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach
                                    </select>
                                </div>
                                @break
                            @case('booking_window')
                                <div>
                                    <label for="r-notice" class="sh-label">Minimum notice (hours)</label>
                                    <input id="r-notice" type="number" min="0" step="0.5" wire:model="config.min_notice_hours" class="sh-input" placeholder="e.g. 24">
                                    @error('config.min_notice_hours') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="r-days" class="sh-label">At most this many days ahead</label>
                                    <input id="r-days" type="number" min="1" wire:model="config.max_days_ahead" class="sh-input" placeholder="e.g. 60">
                                    @error('config.max_days_ahead') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                @break
                            @case('service_area')
                                <div class="sm:col-span-2">
                                    <label for="r-zips" class="sh-label">ZIP codes you serve</label>
                                    <textarea id="r-zips" wire:model="config.postal_codes" rows="2" class="sh-input" placeholder="78701, 78702, 78703"></textarea>
                                    @error('config.postal_codes') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                @break
                            @case('require_detail')
                                <div>
                                    <label for="r-field" class="sh-label">Always get</label>
                                    <select id="r-field" wire:model="config.field" class="sh-input">
                                        @foreach ($details as $key => $label)<option value="{{ $key }}">{{ ucfirst($label) }}</option>@endforeach
                                    </select>
                                </div>
                                @break
                            @case('auto_escalate')
                                <fieldset class="sm:col-span-2">
                                    <legend class="sh-label">Calls about</legend>
                                    <div class="grid gap-1 sm:grid-cols-3">
                                        @foreach ($reasons as $key => $label)
                                            <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" value="{{ $key }}" wire:model="config.reasons" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> {{ $label }}</label>
                                        @endforeach
                                    </div>
                                    @error('config.reasons') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </fieldset>
                                <div>
                                    <label for="r-etype" class="sh-label">Escalate as</label>
                                    <select id="r-etype" wire:model="config.escalation_type" class="sh-input">
                                        @foreach ($escalationTypes as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="r-priority" class="sh-label">Priority</label>
                                    <select id="r-priority" wire:model="config.priority" class="sh-input">
                                        <option value="">Based on the type</option>
                                        @foreach ($priorities as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                                    </select>
                                </div>
                                @break
                        @endswitch
                    </div>
                </div>
                <div class="flex justify-end">
                    <x-ui.button type="submit" variant="secondary">Add rule</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>
</div>
