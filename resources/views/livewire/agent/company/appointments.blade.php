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
                            <li class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm" wire:key="a-{{ $a->id }}">
                                <span class="w-20 font-mono text-muted">{{ $a->starts_at->setTimezone($timezone)->format('g:i A') }}</span>
                                <span class="min-w-0 flex-1 text-ink">{{ $a->service->name ?? $a->title }}
                                    @if ($a->customer)<span class="text-muted"> · <a class="underline" href="{{ route('agent.businesses.customers.show', [$company->ulid, $a->customer->ulid]) }}">{{ $a->customer->fullName() }}</a></span>@endif
                                </span>
                                <x-ui.badge :tone="$a->status->tone()">{{ $a->status->label() }}</x-ui.badge>
                                @if ($a->status->blocksTime() && $a->starts_at->isFuture() && $moving !== $a->id && $cancelling !== $a->id)
                                    <span class="flex gap-1">
                                        @if ($can['move'])<x-ui.button size="sm" variant="ghost" wire:click="startMove({{ $a->id }})">Move</x-ui.button>@endif
                                        @if ($can['cancel'])<x-ui.button size="sm" variant="ghost" wire:click="startCancel({{ $a->id }})">Cancel</x-ui.button>@endif
                                    </span>
                                @endif

                                @if ($moving === $a->id)
                                    <form wire:submit="move" class="basis-full space-y-3 rounded-xl bg-surface-2/60 p-4">
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div>
                                                <label for="mv-date" class="sh-label">New date</label>
                                                <input id="mv-date" type="date" wire:model.live="moveDate" class="sh-input" min="{{ now($timezone)->toDateString() }}">
                                            </div>
                                            <p class="text-xs text-subtle">Only times that fit the business's hours, rules and calendar are shown.</p>
                                        </div>
                                        <div role="radiogroup" aria-label="Free times" class="flex flex-wrap gap-2">
                                            @forelse ($slots as $slot)
                                                <button type="button" role="radio" aria-checked="{{ $moveTime === $slot->format('H:i') ? 'true' : 'false' }}" wire:click="$set('moveTime', '{{ $slot->format('H:i') }}')" @class([
                                                    'rounded-lg px-3 py-1.5 text-sm font-medium',
                                                    'bg-brand-600 text-white' => $moveTime === $slot->format('H:i'),
                                                    'bg-surface text-muted ring-1 ring-line hover:text-ink' => $moveTime !== $slot->format('H:i'),
                                                ])>{{ $slot->format('g:i A') }}</button>
                                            @empty
                                                <p class="text-sm text-muted">No free times that day.</p>
                                            @endforelse
                                        </div>
                                        @error('moveDate') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                                        @error('moveTime') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                                        <div class="flex gap-2">
                                            <x-ui.button type="submit" size="sm">Move appointment</x-ui.button>
                                            <x-ui.button size="sm" variant="ghost" wire:click="close">Keep as is</x-ui.button>
                                        </div>
                                    </form>
                                @elseif ($cancelling === $a->id)
                                    <form wire:submit="cancel" class="basis-full space-y-3 rounded-xl bg-surface-2/60 p-4">
                                        <div>
                                            <label for="cx-reason" class="sh-label">Why is it cancelled?</label>
                                            <input id="cx-reason" type="text" wire:model="cancelReason" class="sh-input" maxlength="250" placeholder="Customer called to cancel; will rebook next month">
                                            @error('cancelReason') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                        </div>
                                        <p class="text-xs text-subtle">The business is told, with your reason.</p>
                                        <div class="flex gap-2">
                                            <x-ui.button type="submit" size="sm" variant="danger">Cancel appointment</x-ui.button>
                                            <x-ui.button size="sm" variant="ghost" wire:click="close">Keep it</x-ui.button>
                                        </div>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
