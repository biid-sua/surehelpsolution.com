<div>
    <x-ui.page-header :title="$organization->name" :back="route('admin.organizations.index')"
        description="{{ number_format($callStats['total']) }} calls in total · {{ number_format($callStats['last30']) }} in the last 30 days">
        <x-slot:actions>
            <x-ui.badge :tone="$organization->status === \App\Enums\OrganizationStatus::Active ? 'success' : 'warning'" class="text-sm">{{ $organization->status->label() }}</x-ui.badge>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Business details --}}
            <x-ui.card title="Business details" description="Times on the client's dashboard and reports use this timezone.">
                <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="org-name" class="sh-label">Business name</label>
                        <input id="org-name" type="text" wire:model="name" class="sh-input" @disabled(! $canUpdate) required maxlength="255">
                        @error('name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="org-timezone" class="sh-label">Timezone</label>
                        <select id="org-timezone" wire:model="timezone" class="sh-input" @disabled(! $canUpdate)>
                            <option value="">Not set</option>
                            <optgroup label="United States">
                                @foreach ($primaryTimezones as $zone => $label)
                                    <option value="{{ $zone }}">{{ $label }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="All timezones">
                                @foreach ($otherTimezones as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                        @error('timezone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    @if ($canUpdate)
                        <div class="sm:col-span-2">
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">Save changes</span>
                                <span wire:loading wire:target="save">Saving…</span>
                            </x-ui.button>
                        </div>
                    @endif
                </form>
            </x-ui.card>

            {{-- Agent assignments --}}
            <x-ui.card title="Assigned agents" description="Only these agents can see this business and log calls for it." :padding="false">
                @if ($agents->isEmpty())
                    <x-ui.empty-state icon="users" title="No agents assigned"
                        description="Calls for this business can't be logged until at least one agent is assigned." />
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($agents as $agent)
                            <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="agent-{{ $agent->id }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-ink">{{ $agent->name }}</p>
                                    <p class="truncate text-xs text-subtle">{{ $agent->email }}
                                        @if ($agent->pivot->source !== 'manual')
                                            · assigned {{ $agent->pivot->source === 'migration' ? 'during migration' : 'automatically' }}
                                        @endif
                                    </p>
                                </div>
                                @if ($canUpdate)
                                    <x-ui.confirm id="unassign-{{ $agent->id }}" title="Remove {{ $agent->name }}?" confirm-label="Remove agent" action="unassignAgent({{ $agent->id }})">
                                        <x-slot:trigger><x-ui.button variant="ghost" size="sm">Remove</x-ui.button></x-slot:trigger>
                                        {{ $agent->name }} will immediately lose access to {{ $organization->name }} and can no longer log or edit its calls.
                                    </x-ui.confirm>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canUpdate)
                    <form wire:submit="assignAgent" class="flex flex-wrap items-end gap-3 border-t border-line px-5 py-4">
                        <div class="min-w-56 flex-1">
                            <label for="agent-to-add" class="sh-label">Add an agent</label>
                            <select id="agent-to-add" wire:model="agentToAdd" class="sh-input" @disabled($availableAgents->isEmpty())>
                                <option value="">{{ $availableAgents->isEmpty() ? 'All active agents are assigned' : 'Choose an agent…' }}</option>
                                @foreach ($availableAgents as $available)
                                    <option value="{{ $available->id }}">{{ $available->name }} ({{ $available->email }})</option>
                                @endforeach
                            </select>
                            @error('agentToAdd') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <x-ui.button type="submit" variant="secondary" icon="users" :disabled="$availableAgents->isEmpty()">Assign</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        </div>

        {{-- Members --}}
        <x-ui.card title="People at this business" :padding="false">
            <ul class="divide-y divide-line">
                @forelse ($organization->members as $member)
                    <li class="px-5 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-medium text-ink">{{ $member->name }}</p>
                            <x-ui.badge :tone="$member->pivot->role === 'owner' ? 'brand' : 'neutral'">{{ config('authorization.organization_roles.'.$member->pivot->role.'.label', $member->pivot->role) }}</x-ui.badge>
                        </div>
                        <p class="truncate text-xs text-subtle">{{ $member->email }}{{ $member->is_active ? '' : ' · deactivated' }}</p>
                    </li>
                @empty
                    <li><x-ui.empty-state icon="user" title="No members" description="This business has no user accounts." /></li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</div>
