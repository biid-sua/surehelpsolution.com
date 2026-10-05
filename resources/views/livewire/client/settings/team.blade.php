<div>
    <x-ui.page-header title="Team" description="People from {{ $organization->name }} who can sign in to this portal. SureHelp receptionists aren't listed: we manage them for you." />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-6">
            <x-ui.card title="Members" :padding="false">
                <x-ui.table>
                    <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col" class="hidden md:table-cell">Last sign-in</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($members as $member)
                            @php
                                $role = $member->pivot->role;
                                $editable = $canManage && $role !== 'owner' && $member->id !== auth()->id();
                            @endphp
                            <tr wire:key="member-{{ $member->id }}">
                                <td>
                                    <p class="font-medium text-ink">{{ $member->name }} @if ($member->id === auth()->id())<span class="text-xs text-subtle">(you)</span>@endif</p>
                                    <p class="text-xs text-subtle">{{ $member->email }}</p>
                                </td>
                                <td>
                                    @if ($editable)
                                        <label for="role-{{ $member->id }}" class="sr-only">Role for {{ $member->name }}</label>
                                        <select id="role-{{ $member->id }}" class="sh-input py-1 text-sm" wire:change="changeRole({{ $member->id }}, $event.target.value)">
                                            @foreach ($roles as $value => $label)
                                                <option value="{{ $value }}" @selected($role === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <x-ui.badge :tone="$role === 'owner' ? 'brand' : 'neutral'">{{ $role === 'owner' ? 'Owner' : ($roles[$role] ?? ucfirst($role)) }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="hidden text-muted md:table-cell">{{ $member->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="text-right">
                                    @if ($editable)
                                        <x-ui.confirm id="remove-{{ $member->id }}" title="Remove {{ $member->name }}?" confirm-label="Remove" action="remove({{ $member->id }})">
                                            <x-slot:trigger><x-ui.button size="sm" variant="ghost">Remove</x-ui.button></x-slot:trigger>
                                            They're signed out straight away and can't open {{ $organization->name }}'s portal any more. Their past activity stays in your records.
                                        </x-ui.confirm>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>

            @if ($invitations->isNotEmpty())
                <x-ui.card title="Waiting to join" :padding="false">
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($invitations as $invitation)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3" wire:key="inv-{{ $invitation->id }}">
                                <div>
                                    <p class="text-sm font-medium text-ink">{{ $invitation->email }} <x-ui.badge tone="info">{{ $invitation->roleLabel() }}</x-ui.badge></p>
                                    <p class="text-xs text-subtle">Invited {{ $invitation->created_at->diffForHumans() }}{{ $invitation->inviter ? ' by '.$invitation->inviter->name : '' }} · expires {{ $invitation->expires_at->format('M j') }}</p>
                                </div>
                                @if ($canInvite)
                                    <div class="flex gap-1">
                                        <x-ui.button size="sm" variant="ghost" wire:click="resend({{ $invitation->id }})">Send again</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" wire:click="revoke({{ $invitation->id }})">Cancel</x-ui.button>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        <div>
            @if ($canInvite)
                <x-ui.card title="Invite someone" description="They get an email with a link to set their password. It works for {{ config('account.invitation_days') }} days.">
                    <form wire:submit="invite" class="space-y-4">
                        <div>
                            <label for="t-email" class="sh-label">Email</label>
                            <input id="t-email" type="email" wire:model="email" class="sh-input" placeholder="name@business.com" autocomplete="off">
                            @error('email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="t-role" class="sh-label">Role</label>
                            <select id="t-role" wire:model="role" class="sh-input">
                                @foreach ($invitable as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('role') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <x-ui.button type="submit" class="w-full">Send invitation</x-ui.button>
                    </form>
                    <dl class="mt-5 space-y-2 border-t border-line pt-4 text-xs text-muted">
                        <div><dt class="inline font-semibold text-ink">Manager:</dt> <dd class="inline">everything except billing, plan changes and removing people.</dd></div>
                        <div><dt class="inline font-semibold text-ink">Staff:</dt> <dd class="inline">calls, appointments, customers, tasks and escalations. No business settings or billing.</dd></div>
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
