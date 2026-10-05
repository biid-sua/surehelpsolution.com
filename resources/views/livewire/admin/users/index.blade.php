<div>
    <x-ui.page-header title="Users" description="Everyone who signs in: your staff, agents and business owners.">
        @if ($canCreate)
            <x-slot:actions>
                <x-ui.button icon="user" wire:click="startAdding">Add user</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($issued)
        <x-ui.alert tone="success" class="mb-6" title="{{ $issued['reason'] === 'created' ? $issued['name'].' can sign in now' : 'New temporary password for '.$issued['name'] }}">
            <p>Share these details privately. The password is shown only this once{{ $issued['reason'] === 'reset' ? ', and the old one no longer works' : '' }}.</p>
            <dl class="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-[auto_1fr]">
                <dt class="text-subtle">Sign-in email</dt><dd class="font-mono text-ink">{{ $issued['email'] }}</dd>
                <dt class="text-subtle">Temporary password</dt><dd class="font-mono text-ink" data-testid="temporary-password">{{ $issued['password'] }}</dd>
            </dl>
            <p class="mt-2 text-xs">Agents and business owners choose their own password the first time they sign in.</p>
            <x-ui.button size="sm" variant="secondary" class="mt-3" wire:click="dismissIssued">Done</x-ui.button>
        </x-ui.alert>
    @endif

    @if ($adding)
        <x-ui.card class="mb-6" title="Add a user" description="A business owner gets their own business automatically. Agents are assigned to businesses under Organizations.">
            <form wire:submit="create" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="u-role" class="sh-label">Type</label>
                    <select id="u-role" wire:model.live="draft.role" class="sh-input">
                        <option value="client">Business owner</option>
                        <option value="agent">Agent</option>
                        @if ($canManageStaff)<option value="admin">Staff (admin console)</option>@endif
                    </select>
                    @error('draft.role') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                @if ($draftRoles)
                    <div>
                        <label for="u-prole" class="sh-label">Role</label>
                        <select id="u-prole" wire:model="draft.platform_role" class="sh-input">
                            @foreach ($draftRoles as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('draft.platform_role') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="u-biz" class="sh-label">Business name</label>
                        <input id="u-biz" type="text" wire:model="draft.business_name" class="sh-input" placeholder="Rivera Plumbing">
                        @error('draft.business_name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="u-name" class="sh-label">Full name</label>
                    <input id="u-name" type="text" wire:model="draft.name" class="sh-input" autocomplete="off">
                    @error('draft.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-email" class="sh-label">Email</label>
                    <input id="u-email" type="email" wire:model="draft.email" class="sh-input" autocomplete="off">
                    @error('draft.email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-phone" class="sh-label">Phone (optional)</label>
                    <input id="u-phone" type="tel" wire:model="draft.phone" class="sh-input">
                    @error('draft.phone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-pass" class="sh-label">Temporary password (optional)</label>
                    <input id="u-pass" type="text" wire:model="draft.password" class="sh-input" placeholder="Leave empty to generate one" autocomplete="new-password">
                    @error('draft.password') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <x-ui.button variant="secondary" wire:click="cancelAdding">Cancel</x-ui.button>
                    <x-ui.button type="submit">Add user</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="User type">
        @foreach (['' => 'All'] + $types as $value => $label)
            <button type="button" wire:click="$set('type', '{{ $value }}')" @class([
                'rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                'bg-brand-600 text-white' => $type === $value,
                'bg-surface-2 text-muted hover:text-ink' => $type !== $value,
            ]) aria-pressed="{{ $type === $value ? 'true' : 'false' }}">
                {{ $label }}
                <span class="ml-1 tabular-nums opacity-70">{{ $value === '' ? $counts->sum() : ($counts[$value] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_14rem] sm:items-end">
        <div>
            <label for="user-search" class="sh-label">Search</label>
            <div class="relative">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
                <input id="user-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input pl-9" placeholder="Name, email, ID or business…" autocomplete="off">
            </div>
        </div>
        <div>
            <label for="user-status" class="sh-label">Status</label>
            <select id="user-status" wire:model.live="status" class="sh-input">
                <option value="">Any status</option>
                <option value="active">Active</option>
                <option value="pending">Hasn't set a password yet</option>
                <option value="off">Switched off</option>
            </select>
        </div>
    </div>

    <x-ui.card :padding="false">
        @if ($users->isEmpty())
            <x-ui.empty-state icon="users" title="No users found" description="{{ $search !== '' || $type !== '' || $status !== '' ? 'Try a different search or filter.' : 'Add your first agent or business owner.' }}" />
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Role</th>
                        <th scope="col" class="hidden md:table-cell">Business</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($users as $u)
                        @php
                            $roles = $this->platformRoles($u->role);
                            $current = $u->roles->first()?->name;
                            $editable = $canUpdate && ($u->role !== 'admin' || $canManageStaff);
                            $self = $u->is(auth()->user());
                        @endphp
                        <tr wire:key="user-{{ $u->id }}">
                            <td>
                                <p class="font-medium text-ink">{{ $u->name }} @if ($self)<span class="text-xs text-subtle">(you)</span>@endif</p>
                                <p class="text-xs text-subtle">{{ $u->email }} · {{ $u->unique_id }}</p>
                            </td>
                            <td class="text-muted">
                                @if ($roles && $editable && ! $self)
                                    <label for="role-{{ $u->id }}" class="sr-only">Role for {{ $u->name }}</label>
                                    <select id="role-{{ $u->id }}" class="sh-input py-1 text-sm" wire:change="changeRole({{ $u->id }}, $event.target.value)">
                                        @foreach ($roles as $value => $label)
                                            <option value="{{ $value }}" @selected($current === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    {{ $current ? config("authorization.roles.{$current}.label") : 'Business owner' }}
                                @endif
                            </td>
                            <td class="hidden text-muted md:table-cell">
                                @forelse ($u->organizations->take(2) as $organization)
                                    <a href="{{ route('admin.organizations.show', $organization) }}" class="block hover:text-ink">{{ $organization->name }}</a>
                                @empty
                                    <span class="text-subtle">—</span>
                                @endforelse
                            </td>
                            <td>
                                @if (! $u->is_active)
                                    <x-ui.badge tone="danger">Switched off</x-ui.badge>
                                @elseif ($u->requiresPasswordChange())
                                    <x-ui.badge tone="warning">Password not set</x-ui.badge>
                                @else
                                    <x-ui.badge tone="success">Active</x-ui.badge>
                                @endif
                                @if ($u->hasTwoFactor())
                                    <x-ui.badge tone="brand" title="Two-step sign-in is on">2-step</x-ui.badge>
                                @elseif ($u->requiresTwoFactor())
                                    <x-ui.badge tone="warning" title="Required; they'll set it up at their next sign-in">No 2-step yet</x-ui.badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if ($canImpersonate && $u->isClient() && $u->is_active)
                                    <form method="POST" action="{{ route('admin.impersonate', $u) }}" class="inline">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="ghost">View as</x-ui.button>
                                    </form>
                                @endif
                                @if ($editable)
                                    <x-ui.confirm id="reset-{{ $u->id }}" title="Reset {{ $u->name }}'s password?" confirm-label="Reset password" tone="primary" action="resetPassword({{ $u->id }})">
                                        <x-slot:trigger><x-ui.button size="sm" variant="ghost">Reset password</x-ui.button></x-slot:trigger>
                                        Their current password stops working and they're signed out of the mobile app. You'll see a temporary password to share with them.
                                    </x-ui.confirm>
                                    @if ($u->hasTwoFactor() && ! $self)
                                        <x-ui.confirm id="reset2fa-{{ $u->id }}" title="Reset {{ $u->name }}'s two-step sign-in?" confirm-label="Reset" tone="primary" action="resetTwoFactor({{ $u->id }})">
                                            <x-slot:trigger><x-ui.button size="sm" variant="ghost">Reset 2-step</x-ui.button></x-slot:trigger>
                                            Use this when they've lost their phone and recovery codes. Check it's really them first. They're signed out everywhere and set it up again at their next sign-in.
                                        </x-ui.confirm>
                                    @endif
                                    @unless ($self)
                                        <x-ui.confirm id="toggle-{{ $u->id }}" title="{{ $u->is_active ? 'Switch off '.$u->name.'?' : 'Switch '.$u->name.' back on?' }}"
                                            confirm-label="{{ $u->is_active ? 'Switch off' : 'Switch on' }}" :tone="$u->is_active ? 'danger' : 'primary'" action="toggleActive({{ $u->id }})">
                                            <x-slot:trigger><x-ui.button size="sm" variant="ghost">{{ $u->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button></x-slot:trigger>
                                            {{ $u->is_active ? 'They are signed out everywhere and can\'t sign in until you switch them back on. Their records stay.' : 'They can sign in again with their current password.' }}
                                        </x-ui.confirm>
                                    @endunless
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$users" />
        @endif
    </x-ui.card>
</div>
