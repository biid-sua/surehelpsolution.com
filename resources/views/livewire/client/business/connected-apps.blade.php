<div>
    <x-ui.page-header title="Business" description="Connected apps: SureHelp working with the software you already use." />
    @include('livewire.client.business._tabs')

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            <x-ui.card title="QuickBooks Online" description="Payments your customers make through SureHelp go into QuickBooks, and your QuickBooks customers come into SureHelp so our receptionists know them by name.">
                @if (! $connection)
                    @if ($configured)
                        <ul class="mb-4 list-disc space-y-1 pl-5 text-sm text-muted">
                            <li>Deposits and pay-by-text payments are added as payments from the customer, ready to apply to their invoice.</li>
                            <li>Your QuickBooks customers are brought in, and matched to the customers you already have by phone or email.</li>
                            <li>Nothing in QuickBooks is changed or deleted, and nothing is sent twice.</li>
                        </ul>
                        <x-ui.button :href="route('app.apps.quickbooks.connect')" icon="link">Connect QuickBooks</x-ui.button>
                        <p class="mt-2 text-xs text-subtle">You'll sign in to QuickBooks and choose your company. You need to be an admin of the company there.</p>
                    @else
                        <p class="text-sm text-muted">Coming soon: we're finishing the QuickBooks setup.</p>
                    @endif
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium text-ink">{{ $connection->account_name ?: 'Your QuickBooks company' }}</p>
                        <x-ui.badge :tone="$connection->isActive() ? 'success' : 'warning'">{{ $connection->isActive() ? 'Connected' : 'Connect again' }}</x-ui.badge>
                    </div>
                    <p class="mt-1 text-xs text-subtle">
                        {{ number_format($customers) }} {{ \Illuminate\Support\Str::plural('customer', $customers) }} matched · {{ number_format($payments) }} {{ \Illuminate\Support\Str::plural('payment', $payments) }} sent
                        @if ($connection->last_synced_at) · last fetched {{ $connection->last_synced_at->diffForHumans() }}@endif
                    </p>
                    @if ($connection->last_error)
                        <x-ui.alert tone="{{ $connection->isActive() ? 'warning' : 'danger' }}" class="mt-3">{{ $connection->last_error }}</x-ui.alert>
                    @endif

                    <form wire:submit="save" class="mt-5 space-y-3 text-sm">
                        <label class="flex items-start gap-2 text-ink">
                            <input type="checkbox" wire:model="settings.send_payments" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                            <span>Send payments to QuickBooks<span class="block text-xs text-muted">Deposits and pay-by-text payments, with the customer who paid.</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-ink">
                            <input type="checkbox" wire:model="settings.send_new_customers" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                            <span>Send every new customer to QuickBooks<span class="block text-xs text-muted">Off: only customers who pay are added there, so new leads don't fill up your books.</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-ink">
                            <input type="checkbox" wire:model="settings.receive_customers" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                            <span>Bring in my QuickBooks customers<span class="block text-xs text-muted">Every hour. Only details missing in SureHelp are filled in; what your team typed is kept.</span></span>
                        </label>
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <x-ui.button type="submit">Save</x-ui.button>
                            @if ($connection->isActive())
                                <x-ui.button type="button" variant="secondary" wire:click="syncNow" icon="refresh">Fetch changes now</x-ui.button>
                            @else
                                <x-ui.button :href="route('app.apps.quickbooks.connect')" variant="secondary">Connect again</x-ui.button>
                            @endif
                        </div>
                    </form>
                    <div class="mt-4 border-t border-line pt-4">
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="disconnect" wire:confirm="Disconnect QuickBooks? Nothing more is sent or brought in. What's already there stays.">Disconnect</x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Recent activity" :padding="false">
                <ul class="divide-y divide-line text-sm" role="list">
                    @forelse ($entries as $entry)
                        <li class="px-5 py-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span @class(['font-medium', 'text-danger' => $entry->action === 'failed', 'text-ink' => $entry->action !== 'failed'])>{{ \App\Models\AppSyncEntry::LABELS[$entry->action] ?? $entry->action }}</span>
                                <span class="text-xs text-subtle">{{ $entry->created_at->timezone($timezone)->format('M j, g:i A') }}</span>
                            </div>
                            <p class="text-muted">{{ $entry->label }}</p>
                            @if ($entry->error)<p class="text-xs text-subtle">{{ $entry->error }}</p>@endif
                        </li>
                    @empty
                        <li class="px-5 py-6 text-subtle">Nothing sent or brought in yet.</li>
                    @endforelse
                </ul>
            </x-ui.card>
            <x-ui.card title="Coming next">
                <p class="text-sm text-muted">Jobber, Housecall Pro and ServiceTitan. Until then, connect them with Zapier or Make under <a href="{{ route('app.business.integrations') }}" wire:navigate class="font-medium text-brand-300 hover:underline">API & webhooks</a>.</p>
            </x-ui.card>
        </div>
    </div>
</div>
