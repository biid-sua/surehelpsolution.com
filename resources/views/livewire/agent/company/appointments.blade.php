<div>
    <x-agent.company-bar :company="$company" active="appointments" />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.button size="sm" variant="ghost" icon="chevron-left" wire:click="shift(-2)" aria-label="Earlier" />
        <p class="font-medium text-ink">{{ $from->format('j M') }} – {{ $to->format('j M Y') }} <span class="text-sm font-normal text-subtle">({{ $timezone }})</span></p>
        <x-ui.button size="sm" variant="ghost" icon="chevron-right" wire:click="shift(2)" aria-label="Later" />
        <x-ui.button size="sm" variant="secondary" class="ml-auto" :href="route('agent.businesses.show', $company->ulid)">Book in the workspace</x-ui.button>
    </div>

    @if ($days->isEmpty())
        <x-ui.card><x-ui.empty-state icon="calendar" title="No appointments in these two weeks" description="Bookings made by agents, the business or its AI assistant appear here." /></x-ui.card>
    @else
        <div class="space-y-4">
            @foreach ($days as $day => $items)
                <x-ui.card :padding="false" wire:key="d-{{ $day }}">
                    <h2 class="border-b border-line px-5 py-3 text-sm font-semibold text-ink">{{ \Carbon\CarbonImmutable::parse($day)->format('l j F') }}</h2>
                    <ul class="divide-y divide-line">
                        @foreach ($items as $a)
                            <li class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                                <span class="w-20 font-mono text-muted">{{ $a->starts_at->setTimezone($timezone)->format('g:i A') }}</span>
                                <span class="min-w-0 flex-1 text-ink">{{ $a->service->name ?? $a->title }}
                                    @if ($a->customer)<span class="text-muted"> · <a class="underline" href="{{ route('agent.businesses.customers.show', [$company->ulid, $a->customer->ulid]) }}">{{ $a->customer->fullName() }}</a></span>@endif
                                </span>
                                <x-ui.badge :tone="$a->status->tone()">{{ $a->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
