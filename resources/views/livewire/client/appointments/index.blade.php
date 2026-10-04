<div>
    <x-ui.page-header title="Appointments" description="Everything booked by our team and yours, in your business's time ({{ $timezone }}).">
        @if ($can['create'])
            <x-slot:actions>
                <x-ui.button icon="calendar" wire:click="book">Book appointment</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Appointment views">
        @foreach ($views as $key => $label)
            <button type="button" role="tab" wire:click="$set('view', '{{ $key }}')" aria-selected="{{ $view === $key ? 'true' : 'false' }}"
                @class([
                    '-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-brand-400 text-ink' => $view === $key,
                    'border-transparent text-muted hover:text-ink' => $view !== $key,
                ])>
                {{ $label }}
                @if ($key === 'unconfirmed' && $unconfirmedCount > 0)<x-ui.badge tone="warning">{{ $unconfirmedCount }}</x-ui.badge>@endif
            </button>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="relative">
            <div wire:loading.flex wire:target="view,nextPage,previousPage" class="absolute inset-0 z-10 items-start justify-center bg-surface/60 pt-16">
                <span class="rounded-full bg-surface-2 px-3 py-1 text-xs text-muted ring-1 ring-line">Loading…</span>
            </div>

            @if ($appointments->isEmpty())
                @if ($view === 'upcoming')
                    <x-ui.empty-state icon="calendar" title="No upcoming appointments"
                        description="Bookings made by our agents or your team appear here, checked against your hours so nothing is ever double-booked.">
                        @if ($can['create'])<x-ui.button variant="secondary" size="sm" wire:click="book">Book appointment</x-ui.button>@endif
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="calendar" title="Nothing here" description="No appointments in this view." />
                @endif
            @else
                @php($currentDay = null)
                <ul role="list">
                    @foreach ($appointments as $appointment)
                        @php($start = $appointment->starts_at->setTimezone($timezone))
                        @if ($currentDay !== $start->toDateString())
                            @php($currentDay = $start->toDateString())
                            <li class="border-b border-line bg-surface-2/60 px-5 py-2 text-xs font-semibold uppercase tracking-wide text-subtle">
                                {{ $currentDay === $today ? 'Today · ' : ($start->isTomorrow() ? 'Tomorrow · ' : '') }}{{ $start->format('l, F j') }}
                            </li>
                        @endif
                        <li wire:key="a-{{ $appointment->ulid }}" class="border-b border-line last:border-0">
                            <button type="button" wire:click="$set('selected', '{{ $appointment->ulid }}')"
                                @class(['flex w-full items-center gap-4 px-5 py-3 text-left hover:bg-surface-2', 'bg-surface-2' => $selected === $appointment->ulid])>
                                <span class="w-24 shrink-0 text-sm font-semibold tabular-nums text-ink">{{ $start->format('g:i A') }}
                                    <span class="block text-xs font-normal text-subtle">{{ $appointment->durationMinutes() }} min</span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-ink">{{ $appointment->title }}</span>
                                    <span class="block truncate text-xs text-muted">
                                        {{ $appointment->customer?->displayPhone() ?? '' }}{{ $appointment->location ? ' · '.$appointment->location->name : '' }}{{ $appointment->address ? ' · '.$appointment->address : '' }}
                                    </span>
                                </span>
                                <x-ui.badge :tone="$appointment->status->tone()">{{ $appointment->status->label() }}</x-ui.badge>
                            </button>
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :paginator="$appointments" />
            @endif
        </div>
    </x-ui.card>

    {{-- Appointment detail --}}
    @if ($selectedAppointment)
        @php($a = $selectedAppointment)
        <div class="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="appt-title" x-data x-on:keydown.escape.window="$wire.set('selected', '')">
            <div class="fixed inset-0 bg-black/50" wire:click="$set('selected', '')"></div>
            <aside class="relative flex h-full w-full max-w-md flex-col overflow-y-auto border-l border-line-strong bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <x-ui.badge :tone="$a->status->tone()">{{ $a->status->label() }}</x-ui.badge>
                        <h2 id="appt-title" class="mt-2 text-lg font-semibold text-ink">{{ $a->title }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $a->whenLabel() }} <span class="text-subtle">({{ $a->localStart()->format('T') }})</span></p>
                    </div>
                    <button type="button" wire:click="$set('selected', '')" class="rounded-lg p-1 text-muted hover:bg-surface-2 hover:text-ink" aria-label="Close"><x-ui.icon name="x" class="size-5" /></button>
                </div>

                <dl class="mt-6 space-y-4 text-sm">
                    @if ($a->customer)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-subtle">Customer</dt>
                            <dd class="mt-1"><a href="{{ route('app.customers.show', $a->customer->ulid) }}" class="font-medium text-ink hover:text-brand-300">{{ $a->customer->fullName() }}</a>
                                @if ($a->customer->displayPhone())<span class="block text-muted">{{ $a->customer->displayPhone() }}</span>@endif</dd>
                        </div>
                    @endif
                    @if ($a->service)
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-subtle">Service</dt><dd class="mt-1 text-ink">{{ $a->service->name }}</dd></div>
                    @endif
                    @if ($a->location || $a->address)
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-subtle">Where</dt><dd class="mt-1 text-ink">{{ collect([$a->location?->name, $a->address])->filter()->join(' · ') }}</dd></div>
                    @endif
                    @if ($a->notes)
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-subtle">Notes</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $a->notes }}</dd></div>
                    @endif
                    @if ($a->cancellation_reason)
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-subtle">Why it was cancelled</dt><dd class="mt-1 text-ink">{{ $a->cancellation_reason }}</dd></div>
                    @endif
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-subtle">Booked</dt>
                        <dd class="mt-1 text-muted">{{ $a->created_at->setTimezone($timezone)->format('M j, g:i A') }} by {{ $a->bookedBy->name ?? 'SureHelp' }}{{ $a->source === 'agent' ? ' (our team)' : '' }}
                            @if ($a->call) · <a href="{{ route('app.calls.show', $a->call->call_id) }}" class="hover:text-ink">call {{ $a->call->call_id }}</a>@endif</dd>
                    </div>
                </dl>

                <div class="mt-auto space-y-3 pt-8">
                    @php($next = $a->status->transitions())
                    <div class="flex flex-wrap gap-2">
                        @if ($can['update'])
                            @if (in_array(\App\Enums\AppointmentStatus::Confirmed, $next, true))
                                <x-ui.button size="sm" wire:click="setStatus('{{ $a->ulid }}', 'confirmed')">{{ $a->status->blocksTime() ? 'Confirm' : 'Undo' }}</x-ui.button>
                            @endif
                            @if (in_array(\App\Enums\AppointmentStatus::Completed, $next, true))
                                <x-ui.button size="sm" wire:click="setStatus('{{ $a->ulid }}', 'completed')">Mark completed</x-ui.button>
                            @endif
                            @if (in_array(\App\Enums\AppointmentStatus::NoShow, $next, true))
                                <x-ui.button size="sm" variant="secondary" wire:click="setStatus('{{ $a->ulid }}', 'no_show')">No-show</x-ui.button>
                            @endif
                            @if ($a->status->blocksTime())
                                <x-ui.button size="sm" variant="secondary" wire:click="startReschedule('{{ $a->ulid }}')">Move</x-ui.button>
                            @endif
                        @endif
                    </div>
                    @if ($can['cancel'] && in_array(\App\Enums\AppointmentStatus::Cancelled, $next, true))
                        <div x-data="{ open: false }" class="border-t border-line pt-3">
                            <x-ui.button size="sm" variant="ghost" x-show="!open" x-on:click="open = true">Cancel appointment…</x-ui.button>
                            <form x-show="open" x-cloak wire:submit="setStatus('{{ $a->ulid }}', 'cancelled')" class="space-y-2">
                                <label for="cancel-reason" class="sh-label">Reason (optional)</label>
                                <input id="cancel-reason" type="text" wire:model="cancelReason" class="sh-input" maxlength="250" placeholder="e.g. Customer rescheduling with us later">
                                <div class="flex gap-2">
                                    <x-ui.button type="submit" size="sm" variant="secondary">Cancel appointment</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" x-on:click="open = false">Keep it</x-ui.button>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            </aside>
        </div>
    @endif

    {{-- Book / move --}}
    @if ($can['create'] || $can['update'])
        <div x-data="{ open: $wire.entangle('booking') }" x-show="open" x-cloak x-on:keydown.escape.window="$wire.close()"
            class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="book-title">
            <div class="fixed inset-0 bg-black/60" x-on:click="$wire.close()"></div>
            <form wire:submit="save" class="relative max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
                <h2 id="book-title" class="text-lg font-semibold text-ink">{{ $rescheduling ? 'Move appointment' : 'Book an appointment' }}</h2>
                <p class="mt-1 text-sm text-muted">Times are in {{ $timezone }}.</p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    @unless ($rescheduling)
                        <div class="sm:col-span-2">
                            <span class="sh-label">Customer</span>
                            @if ($pickedCustomer)
                                <div class="flex items-center justify-between rounded-xl bg-surface-2 px-3 py-2 ring-1 ring-line">
                                    <span class="text-sm text-ink">{{ $pickedCustomer->fullName() }} <span class="text-muted">{{ $pickedCustomer->displayPhone() }}</span></span>
                                    <x-ui.button size="sm" variant="ghost" wire:click="clearCustomer">Change</x-ui.button>
                                </div>
                            @else
                                <label for="cust-find" class="sr-only">Find a customer</label>
                                <input id="cust-find" type="search" wire:model.live.debounce.300ms="customerSearch" class="sh-input" placeholder="Search name, phone or email…" autocomplete="off">
                                @if ($customerMatches->isNotEmpty())
                                    <ul class="mt-1 divide-y divide-line rounded-xl bg-surface-2 ring-1 ring-line" role="listbox">
                                        @foreach ($customerMatches as $match)
                                            <li><button type="button" wire:click="pickCustomer({{ $match->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-3">{{ $match->fullName() }} <span class="text-muted">{{ $match->displayPhone() ?? $match->email }}</span></button></li>
                                        @endforeach
                                    </ul>
                                @endif
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <input type="text" wire:model="form.new_name" class="sh-input" placeholder="…or new customer's name" aria-label="New customer name">
                                    <input type="tel" wire:model="form.new_phone" class="sh-input" placeholder="Phone" aria-label="New customer phone">
                                </div>
                            @endif
                        </div>
                        <div>
                            <label for="b-service" class="sh-label">Service</label>
                            <select id="b-service" wire:model.live="form.service_id" class="sh-input">
                                <option value="">No specific service</option>
                                @foreach ($services as $service)<option value="{{ $service->id }}">{{ $service->name }} ({{ $service->duration_minutes }} min)</option>@endforeach
                            </select>
                            @error('form.service_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        @if ($locations->count() > 1)
                            <div>
                                <label for="b-location" class="sh-label">Location</label>
                                <select id="b-location" wire:model.live="form.location_id" class="sh-input">
                                    <option value="">Any / on site</option>
                                    @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                                </select>
                            </div>
                        @endif
                    @endunless

                    <div>
                        <label for="b-date" class="sh-label">Date</label>
                        <input id="b-date" type="date" wire:model.live="form.date" min="{{ $today }}" class="sh-input">
                        @error('form.date') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="b-duration" class="sh-label">Length (minutes)</label>
                        <input id="b-duration" type="number" min="5" max="1440" step="5" wire:model.live.debounce.500ms="form.duration_minutes" class="sh-input">
                        @error('form.duration_minutes') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <span class="sh-label">Time</span>
                        @if ($slots !== [])
                            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Free times">
                                @foreach ($slots as $slot)
                                    <button type="button" role="radio" aria-checked="{{ ($form['time'] ?? '') === $slot->format('H:i') ? 'true' : 'false' }}"
                                        wire:click="pickTime('{{ $slot->format('Y-m-d H:i') }}')"
                                        @class(['rounded-lg px-3 py-1.5 text-sm tabular-nums ring-1 transition-colors',
                                            'bg-brand-600 text-white ring-brand-500' => ($form['time'] ?? '') === $slot->format('H:i'),
                                            'bg-surface-2 text-ink ring-line hover:ring-brand-400' => ($form['time'] ?? '') !== $slot->format('H:i')])>{{ $slot->format('g:i A') }}</button>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-muted">{{ $hasHours ? 'No free times that day.' : 'Add opening hours to see free times.' }} Enter a time below.</p>
                        @endif
                        <div class="mt-2 flex items-center gap-2">
                            <label for="b-time" class="text-sm text-subtle">{{ $slots !== [] ? 'Or another time' : 'Time' }}</label>
                            <input id="b-time" type="time" wire:model="form.time" class="sh-input w-36">
                        </div>
                        @error('form.time') <p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p> @enderror
                        @if ($suggestions !== [])
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($suggestions as $suggestion)
                                    <x-ui.button size="sm" variant="secondary" wire:click="pickTime('{{ $suggestion }}')">{{ \Carbon\CarbonImmutable::parse($suggestion)->format('D j M, g:i A') }}</x-ui.button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @unless ($rescheduling)
                        <div>
                            <label for="b-status" class="sh-label">Status</label>
                            <select id="b-status" wire:model="form.status" class="sh-input">
                                @foreach ($statuses as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="b-address" class="sh-label">Address (for visits)</label>
                            <input id="b-address" type="text" wire:model="form.address" class="sh-input" maxlength="255">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="b-notes" class="sh-label">Notes</label>
                            <textarea id="b-notes" wire:model="form.notes" rows="2" class="sh-input"></textarea>
                        </div>
                    @endunless
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$wire.close()">Close</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $rescheduling ? 'Move' : 'Book' }}</x-ui.button>
                </div>
            </form>
        </div>
    @endif
</div>
