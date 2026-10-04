@php
    $fields = [
        'Caller' => \App\Models\CallLog::display($call->caller_name),
        'Phone' => \App\Models\CallLog::display($call->caller_phone),
        'Email' => \App\Models\CallLog::display($call->caller_email),
        'Reason' => \Illuminate\Support\Str::headline($call->reason_for_call),
        'Outcome' => \Illuminate\Support\Str::headline($call->call_outcome),
        'Handled by' => \App\Models\CallLog::display($call->agent_name),
        'Logged' => $call->created_at->setTimezone($timezone)->format('l, M j, Y \a\t g:i a'),
    ];
@endphp

<div>
    <x-ui.page-header :title="\App\Models\CallLog::display($call->caller_name)" :back="route('app.calls.index')"
        description="Call {{ $call->call_id }}">
        <x-slot:actions>
            <x-ui.badge :tone="$call->statusTone()" class="text-sm">{{ $call->statusLabel() }}</x-ui.badge>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Call details">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    @foreach ($fields as $label => $value)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-subtle">{{ $label }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="Agent notes">
                @if (filled($call->notes))
                    <p class="whitespace-pre-line text-sm leading-relaxed text-ink">{{ $call->notes }}</p>
                @else
                    <p class="text-sm text-muted">The agent didn't add notes to this call.</p>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6">
            @if ($call->customer)
                <x-ui.card title="Customer">
                    <a href="{{ route('app.customers.show', $call->customer) }}" class="font-medium text-ink hover:text-brand-300">{{ $call->customer->fullName() }}</a>
                    <p class="mt-1 text-sm text-muted">{{ $call->customer->displayPhone() ?? $call->customer->email }}</p>
                    <x-ui.button class="mt-4 w-full" variant="secondary" size="sm" :href="route('app.customers.show', $call->customer)">View full history</x-ui.button>
                </x-ui.card>
            @endif

            @foreach ($callEscalations as $escalation)
                <x-ui.card title="Escalation">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge :tone="$escalation->priority->tone()">{{ $escalation->priority->label() }}</x-ui.badge>
                        <x-ui.badge :tone="$escalation->status->tone()">{{ $escalation->status->label() }}</x-ui.badge>
                    </div>
                    <p class="mt-2 text-sm font-medium text-ink">{{ $escalation->type->label() }}</p>
                    @if ($escalation->resolution_notes)<p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $escalation->resolution_notes }}</p>@endif
                    <x-ui.button class="mt-4 w-full" variant="secondary" size="sm" :href="route('app.escalations.index', ['escalation' => $escalation->ulid])">Open escalation</x-ui.button>
                </x-ui.card>
            @endforeach

            @if ($callTasks !== null && $callTasks->isNotEmpty())
                @include('livewire.client.tasks._card', ['cardTasks' => $callTasks])
            @endif

            <x-ui.card title="Service visit">
                @if ($call->hasScheduledService())
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-subtle">Date</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $call->service_date?->format('l, M j, Y') ?? 'To be confirmed' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-subtle">Time window</dt>
                            <dd class="mt-1 text-sm text-ink">{{ \App\Models\CallLog::display($call->service_window) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-subtle">Location</dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-ink">{{ \App\Models\CallLog::display($call->service_location) }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-muted">No service visit was requested on this call.</p>
                @endif
            </x-ui.card>

            @if ($call->caller_phone)
                <x-ui.card title="Follow up">
                    <p class="text-sm text-muted">Call the customer back directly.</p>
                    <x-ui.button class="mt-4 w-full" icon="phone" href="tel:{{ preg_replace('/[^0-9+]/', '', $call->caller_phone) }}">Call {{ $call->caller_phone }}</x-ui.button>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
