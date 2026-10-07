<div>
    <x-ui.page-header :title="$organization->name" :back="route('admin.organizations.index')"
        description="{{ number_format($callStats['total']) }} calls in total · {{ number_format($callStats['last30']) }} in the last 30 days">
        <x-slot:actions>
            <x-ui.badge :tone="$organization->status === \App\Enums\OrganizationStatus::Active ? 'success' : 'warning'" class="text-sm">{{ $organization->status->label() }}</x-ui.badge>
            @if ($organization->isClosing())<x-ui.badge tone="danger" class="text-sm">Closes {{ $organization->closes_at->format('M j') }}</x-ui.badge>@endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Business details --}}
            @php
                $serviceText = [
                    'onboarding' => 'Setting up. Go live when the phone line is connected and the setup has been checked.',
                    'active' => 'Live: agents answer calls and the website tools show.',
                    'paused' => "Paused: agents don't see this business and the website tools are hidden.",
                    'cancelled' => 'Cancelled: no service. The owner can still sign in and download their data.',
                ][$organization->status->value];
                $statusLabels = [
                    'active' => $organization->status->value === 'onboarding' ? 'Go live' : ($organization->status->value === 'cancelled' ? 'Reactivate' : 'Resume'),
                    'paused' => 'Pause',
                    'cancelled' => 'Cancel service',
                ];
                $statusHelp = [
                    'active' => 'Agents see this business again and its website tools show. The owner gets an email.',
                    'paused' => 'Agents stop seeing this business and its website tools are hidden until you resume. The owner gets an email with your reason.',
                    'cancelled' => 'The service stops. The owner keeps access to download their data and gets an email with your reason.',
                ];
            @endphp
            <x-ui.card title="Service" :description="$serviceText">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :tone="$organization->status->value === 'active' ? 'success' : ($organization->status->value === 'cancelled' ? 'danger' : 'warning')" class="text-sm">{{ $organization->status->label() }}</x-ui.badge>
                    @if ($organization->status_changed_at)<span class="text-xs text-subtle">since {{ $organization->status_changed_at->format('M j, Y') }}</span>@endif
                </div>
                @if ($organization->status_reason)<p class="mt-2 text-sm text-muted">Reason: {{ $organization->status_reason }}</p>@endif
                @if ($organization->status->value === 'onboarding' && ! $organization->isSetUp())
                    <p class="mt-2 text-sm text-amber-300">Setup isn't finished yet ({{ $setupCount['done'] }} of {{ $setupCount['total'] }} steps).</p>
                @endif
                @if ($canUpdate && $transitions && ! $organization->closed_at)
                    <div class="mt-4 space-y-3">
                        @if (array_intersect($transitions, ['paused', 'cancelled']))
                            <div>
                                <label for="status-reason" class="sh-label">Reason <span class="font-normal text-subtle">(needed to pause or cancel; the owner sees it)</span></label>
                                <input id="status-reason" type="text" wire:model="statusReason" class="sh-input" maxlength="500" placeholder="e.g. Invoice INV-2026-0042 is 30 days overdue">
                                @error('reason') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        @error('status') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap gap-2">
                            @foreach ($transitions as $to)
                                <x-ui.confirm id="status-{{ $to }}" :title="$statusLabels[$to].'?'" :confirm-label="$statusLabels[$to]" :tone="$to === 'active' ? 'primary' : 'danger'" :action="'set'.ucfirst($to)">
                                    <x-slot:trigger><x-ui.button size="sm" :variant="$to === 'active' ? 'primary' : ($to === 'cancelled' ? 'danger' : 'secondary')">{{ $statusLabels[$to] }}</x-ui.button></x-slot:trigger>
                                    {{ $statusHelp[$to] }}
                                </x-ui.confirm>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>

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
                                @if ($canAssign)
                                    <x-ui.confirm id="unassign-{{ $agent->id }}" title="Remove {{ $agent->name }}?" confirm-label="Remove agent" action="unassignAgent({{ $agent->id }})">
                                        <x-slot:trigger><x-ui.button variant="ghost" size="sm">Remove</x-ui.button></x-slot:trigger>
                                        {{ $agent->name }} will immediately lose access to {{ $organization->name }} and can no longer log or edit its calls.
                                    </x-ui.confirm>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canAssign)
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

        <div class="space-y-6">
        {{-- Setup wizard progress (spec ONB): where the business is stuck --}}
        <x-ui.card title="Setup" :description="$organization->isSetUp() ? 'Finished '.$organization->setup_completed_at->format('M j, Y') : $setupCount['done'].' of '.$setupCount['total'].' steps done'" :padding="false">
            <ul class="divide-y divide-line">
                @foreach (\App\Services\Setup\SetupProgress::STEPS as $key => [$title])
                    @continue($key === 'review')
                    @php $state = ($organization->setup_progress ?? [])[$key] ?? null; @endphp
                    <li class="flex items-center justify-between px-5 py-2.5 text-sm">
                        <span class="text-ink">{{ $title }}</span>
                        <x-ui.badge :tone="$state === 'done' ? 'success' : ($state === 'skipped' ? 'neutral' : 'warning')">{{ $state === 'done' ? 'Done' : ($state === 'skipped' ? 'Skipped' : 'Not yet') }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

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
                        @if ($canImpersonate && $member->is_active)
                            <form method="POST" action="{{ route('admin.impersonate', $member) }}" class="mt-2">
                                @csrf
                                <x-ui.button type="submit" size="sm" variant="secondary" icon="user">View as {{ \Illuminate\Support\Str::before($member->name.' ', ' ') }}</x-ui.button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li><x-ui.empty-state icon="user" title="No members" description="This business has no user accounts." /></li>
                @endforelse
            </ul>
        </x-ui.card>
        </div>
    </div>
</div>
