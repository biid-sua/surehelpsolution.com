@php
    $copy = [
        'quickbooks' => [
            'title' => 'QuickBooks Online',
            'description' => 'Payments your customers make through SureHelp go into QuickBooks, and your QuickBooks customers come into SureHelp so our receptionists know them by name.',
            'points' => [
                'Deposits and pay-by-text payments are added as payments from the customer, ready to apply to their invoice.',
                'Your QuickBooks customers are brought in, and matched to the customers you already have by phone or email.',
                'Nothing in QuickBooks is changed or deleted, and nothing is sent twice.',
            ],
            'admin' => 'You\'ll sign in to QuickBooks and choose your company. You need to be an admin of the company there.',
            'settings' => [
                'send_payments' => ['Send payments to QuickBooks', 'Deposits and pay-by-text payments, with the customer who paid.'],
                'send_new_customers' => ['Send every new customer to QuickBooks', 'Off: only customers who pay are added there, so new leads don\'t fill up your books.'],
                'receive_customers' => ['Bring in my QuickBooks customers', 'Every hour. Only details missing in SureHelp are filled in; what your team typed is kept.'],
            ],
        ],
        'jobber' => [
            'title' => 'Jobber',
            'description' => 'Jobs we book for you go into Jobber as new requests, and your Jobber clients come into SureHelp so our receptionists know them by name.',
            'points' => [
                'Each booking becomes a request from the client in Jobber, with the service and the time, ready for you to schedule.',
                'Your Jobber clients are brought in, and matched to the customers you already have by phone or email.',
                'Nothing in Jobber is changed or deleted, and nothing is sent twice.',
            ],
            'admin' => 'You\'ll sign in to Jobber and allow SureHelp. You need to be the account owner or an admin there.',
            'settings' => [
                'send_bookings' => ['Send bookings to Jobber', 'Every job SureHelp books, as a request from the client.'],
                'send_new_customers' => ['Send every new customer to Jobber', 'Off: only customers with a booking are added there, so new leads don\'t fill up your client list.'],
                'receive_customers' => ['Bring in my Jobber clients', 'Every hour. Only details missing in SureHelp are filled in; what your team typed is kept.'],
            ],
        ],
    ];
@endphp
<div>
    <x-ui.page-header title="Business" description="Connected apps: SureHelp working with the software you already use." />
    @include('livewire.client.business._tabs')

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            @foreach ($apps as $app => $state)
                @php
                    $c = $copy[$app];
                    $connection = $state['connection'];
                @endphp
                <x-ui.card :title="$c['title']" :description="$c['description']" wire:key="app-{{ $app }}">
                    @if (! $connection)
                        @if ($state['configured'])
                            <ul class="mb-4 list-disc space-y-1 pl-5 text-sm text-muted">
                                @foreach ($c['points'] as $point)<li>{{ $point }}</li>@endforeach
                            </ul>
                            <x-ui.button :href="route('app.apps.'.$app.'.connect')" icon="link">Connect {{ $names[$app] }}</x-ui.button>
                            <p class="mt-2 text-xs text-subtle">{{ $c['admin'] }}</p>
                        @else
                            <p class="text-sm text-muted">Coming soon: we're finishing the {{ $names[$app] }} setup.</p>
                        @endif
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium text-ink">{{ $connection->account_name ?: 'Your '.$names[$app].' account' }}</p>
                            <x-ui.badge :tone="$connection->isActive() ? 'success' : 'warning'">{{ $connection->isActive() ? 'Connected' : 'Connect again' }}</x-ui.badge>
                        </div>
                        <p class="mt-1 text-xs text-subtle">
                            {{ number_format($state['customers']) }} {{ \Illuminate\Support\Str::plural('customer', $state['customers']) }} matched
                            @if ($app === 'quickbooks') · {{ number_format($state['payments']) }} {{ \Illuminate\Support\Str::plural('payment', $state['payments']) }} sent @else · {{ number_format($state['bookings']) }} {{ \Illuminate\Support\Str::plural('booking', $state['bookings']) }} sent @endif
                            @if ($connection->last_synced_at) · last fetched {{ $connection->last_synced_at->diffForHumans() }}@endif
                        </p>
                        @if ($connection->last_error)
                            <x-ui.alert tone="{{ $connection->isActive() ? 'warning' : 'danger' }}" class="mt-3">{{ $connection->last_error }}</x-ui.alert>
                        @endif

                        <form wire:submit="save('{{ $app }}')" class="mt-5 space-y-3 text-sm">
                            @foreach ($c['settings'] as $key => [$label, $hint])
                                <label class="flex items-start gap-2 text-ink">
                                    <input type="checkbox" wire:model="settings.{{ $app }}.{{ $key }}" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                    <span>{{ $label }}<span class="block text-xs text-muted">{{ $hint }}</span></span>
                                </label>
                            @endforeach
                            <div class="flex flex-wrap items-center gap-2 pt-1">
                                <x-ui.button type="submit">Save</x-ui.button>
                                @if ($connection->isActive())
                                    <x-ui.button type="button" variant="secondary" wire:click="syncNow('{{ $app }}')" icon="refresh">Fetch changes now</x-ui.button>
                                @else
                                    <x-ui.button :href="route('app.apps.'.$app.'.connect')" variant="secondary">Connect again</x-ui.button>
                                @endif
                            </div>
                        </form>
                        <div class="mt-4 border-t border-line pt-4">
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="disconnect('{{ $app }}')" wire:confirm="Disconnect {{ $names[$app] }}? Nothing more is sent or brought in. What's already there stays.">Disconnect</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endforeach
        </div>

        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Recent activity" :padding="false">
                <ul class="divide-y divide-line text-sm" role="list">
                    @forelse ($entries as $entry)
                        <li class="px-5 py-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span @class(['font-medium', 'text-danger' => $entry->action === 'failed', 'text-ink' => $entry->action !== 'failed'])>{{ \App\Models\AppSyncEntry::LABELS[$entry->action] ?? $entry->action }}</span>
                                <span class="text-xs text-subtle">{{ $names[$providers[$entry->app_connection_id] ?? ''] ?? '' }} · {{ $entry->created_at->timezone($timezone)->format('M j, g:i A') }}</span>
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
                <p class="text-sm text-muted">Housecall Pro and ServiceTitan. Until then, connect them with Zapier or Make under <a href="{{ route('app.business.integrations') }}" wire:navigate class="font-medium text-brand-300 hover:underline">API & webhooks</a>.</p>
            </x-ui.card>
        </div>
    </div>
</div>
