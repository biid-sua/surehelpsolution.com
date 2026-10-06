<div>
    <x-ui.page-header title="Training management" description="Certifications held by the agents you look after." />

    <x-university.manage-nav active="certificates" />

    <div class="mb-4 flex flex-wrap gap-3">
        <div class="w-full max-w-xs">
            <label for="ce-search" class="sr-only">Search agent or certificate ID</label>
            <input id="ce-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Agent or certificate ID…">
        </div>
        <div>
            <label for="ce-st" class="sr-only">Status</label>
            <select id="ce-st" wire:model.live="status" class="sh-input">
                <option value="">Any status</option>
                <option value="active">Active</option>
                <option value="expiring_soon">Expiring soon</option>
                <option value="expired">Expired</option>
                <option value="revoked">Revoked</option>
            </select>
        </div>
    </div>

    <x-ui.card :padding="false">
        <x-ui.table>
            <thead><tr><th scope="col">Agent</th><th scope="col">Certification</th><th scope="col">Issued</th><th scope="col">Expires</th><th scope="col">Status</th><th scope="col" class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($certificates as $cert)
                    <tr wire:key="ce-{{ $cert->id }}">
                        <td class="font-medium text-ink">{{ $cert->agent->name }}</td>
                        <td>
                            <a href="{{ route('agent.university.certificate', $cert->ulid) }}" class="text-sm text-ink hover:text-brand-300">{{ $cert->name }}</a>
                            <p class="text-xs text-subtle">{{ $cert->number }} · {{ $cert->course->title }} v{{ $cert->course_version }}</p>
                        </td>
                        <td class="text-sm text-muted">{{ $cert->issued_at->format('j M Y') }}</td>
                        <td class="text-sm text-muted">{{ $cert->expires_at?->format('j M Y') ?? 'Never' }}</td>
                        <td><x-ui.badge :tone="$cert->tone()">{{ $cert->label() }}</x-ui.badge></td>
                        <td class="text-right">
                            @if ($cert->status === 'active' && $canRevoke($cert))
                                <x-ui.button size="sm" variant="ghost" wire:click="startRevoke({{ $cert->id }})">Revoke</x-ui.button>
                            @endif
                        </td>
                    </tr>
                    @if ($revoking === $cert->id)
                        <tr wire:key="cr-{{ $cert->id }}">
                            <td colspan="6">
                                <form wire:submit="revoke" class="flex flex-wrap items-start gap-2">
                                    <div class="min-w-64 flex-1">
                                        <label for="ce-reason" class="sr-only">Reason</label>
                                        <input id="ce-reason" type="text" wire:model="reason" class="sh-input" placeholder="Why is this certificate revoked?" maxlength="500">
                                        @error('reason')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                                    </div>
                                    <x-ui.button type="submit" variant="danger">Revoke certificate</x-ui.button>
                                    <x-ui.button variant="ghost" wire:click="cancelRevoke">Cancel</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="shield" title="No certifications" description="Courses that award a certification issue one when an agent completes them." /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
        <x-ui.pagination :paginator="$certificates" />
    </x-ui.card>
</div>
