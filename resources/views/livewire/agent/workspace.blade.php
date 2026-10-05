<div>
    {{-- Header: who we are answering for, their local time and whether they're open --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('agent.home') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><x-ui.icon name="arrow-left" class="size-4" /> All businesses</a>
            <h1 class="mt-2 text-2xl font-semibold text-ink">{{ $organization->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
                <span @class(['inline-flex items-center gap-1.5 font-medium', 'text-emerald-300' => $status['open'], 'text-amber-300' => ! $status['open']])>
                    <span @class(['size-2 rounded-full', 'bg-emerald-400' => $status['open'], 'bg-amber-400' => ! $status['open']])></span>{{ $status['label'] }}
                </span>
                <span>· {{ $localNow->format('l g:i A') }} their time ({{ $timezone }})</span>
                @if ($activeEscalations > 0)<x-ui.badge tone="danger">{{ $activeEscalations }} open escalation{{ $activeEscalations === 1 ? '' : 's' }}</x-ui.badge>@endif
            </p>
        </div>
        @if ($lastSaved)
            <p class="rounded-lg bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300 ring-1 ring-emerald-500/30" role="status">Last call saved: <span class="font-mono">{{ $lastSaved }}</span></p>
        @endif
    </div>

    @if ($calendarBroken)
        <x-ui.alert tone="warning" class="mb-6" title="Calendar not synced">This business's calendar connection is broken: their latest busy times may be missing. Confirm the time with the owner before booking.</x-ui.alert>
    @endif

    @php $localToday = now($organization->timezoneOrDefault())->toDateString(); @endphp
    @if ($profile?->isAwayOn($localToday))
        <x-ui.alert tone="warning" class="mb-6" title="On vacation until {{ $profile->closed_until->format('l, M j') }}: don't book visits before then">{{ $profile->closure_message ?: 'Take a message and offer a call back after they return.' }}</x-ui.alert>
    @elseif ($profile?->hasAwayAhead($localToday))
        <x-ui.alert tone="info" class="mb-6" title="Away {{ $profile->closed_from->format('M j') }} – {{ $profile->closed_until->format('M j') }}">Bookings for those days aren't possible. {{ $profile->closure_message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-5">
        {{-- Call entry (spec §15) --}}
        <form wire:submit="save" class="space-y-6 lg:col-span-3" aria-label="Log this call">
            <x-ui.card title="1. Who is calling?">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="c-phone" class="sh-label">Phone</label>
                        <input id="c-phone" type="tel" wire:model.live.debounce.400ms="entry.phone" class="sh-input" placeholder="(512) 555-0100" autocomplete="off" autofocus>
                        @error('entry.phone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="c-name" class="sh-label">Name</label>
                        <input id="c-name" type="text" wire:model="entry.name" class="sh-input" autocomplete="off">
                    </div>
                    <div>
                        <label for="c-email" class="sh-label">Email</label>
                        <input id="c-email" type="email" wire:model.live.debounce.600ms="entry.email" class="sh-input" autocomplete="off">
                        @error('entry.email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="c-address" class="sh-label">Address</label>
                        <input id="c-address" type="text" wire:model="entry.address" class="sh-input" placeholder="Street, city, ZIP" autocomplete="off">
                        @error('entry.address') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Customer match: the agent confirms before anything is merged --}}
                <div class="mt-4" aria-live="polite">
                    @if ($confirmed)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-emerald-500/10 px-4 py-3 ring-1 ring-emerald-500/30">
                            <p class="text-sm text-ink"><x-ui.icon name="check-circle" class="mr-1 inline size-4 text-emerald-300" />Existing customer: <span class="font-semibold">{{ $confirmed->fullName() }}</span> <span class="text-muted">· {{ $confirmed->status->label() }}</span></p>
                            <x-ui.button size="sm" variant="ghost" wire:click="differentPerson">Not them</x-ui.button>
                        </div>
                    @elseif ($match)
                        <div class="rounded-xl bg-amber-500/10 px-4 py-3 ring-1 ring-amber-500/30">
                            <p class="text-sm text-ink">This {{ $match['matched_by'] === 'phone' ? 'number' : 'email' }} belongs to <span class="font-semibold">{{ $match['customer']->fullName() }}</span>. Is that who's calling?</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <x-ui.button size="sm" wire:click="confirmCustomer({{ $match['customer']->id }})">Yes, it's them</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="differentPerson">Different person</x-ui.button>
                            </div>
                        </div>
                    @elseif ($newCustomer)
                        <p class="text-sm text-muted">A new customer record will be created for this caller.</p>
                    @else
                        <label for="c-find" class="sr-only">Find an existing customer</label>
                        <input id="c-find" type="search" wire:model.live.debounce.300ms="customerSearch" class="sh-input" placeholder="Or search this business's customers by name…" autocomplete="off">
                        @if ($customerMatches->isNotEmpty())
                            <ul class="mt-1 divide-y divide-line rounded-xl bg-surface-2 ring-1 ring-line">
                                @foreach ($customerMatches as $c)
                                    <li><button type="button" wire:click="confirmCustomer({{ $c->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-3">{{ $c->fullName() }} <span class="text-muted">{{ $c->displayPhone() ?? $c->email }}</span></button></li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                    @error('customer') <p class="mt-2 text-sm font-medium text-danger" role="alert">{{ $message }}</p> @enderror
                </div>
            </x-ui.card>

            <x-ui.card title="2. Why did they call, and what happened?">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="c-reason" class="sh-label">Reason</label>
                        <select id="c-reason" wire:model="entry.reason" class="sh-input">
                            <option value="">Choose…</option>
                            @foreach ($reasons as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('entry.reason') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="c-outcome" class="sh-label">Outcome</label>
                        <select id="c-outcome" wire:model.live="entry.outcome" class="sh-input">
                            <option value="">Choose…</option>
                            @foreach ($outcomeMenu as $o)<option value="{{ $o['key'] }}">{{ $o['label'] }}</option>@endforeach
                        </select>
                        @error('entry.outcome') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    @if ($isEscalation)
                        <div class="sm:col-span-2">
                            <label for="c-etype" class="sh-label">What kind of escalation?</label>
                            <select id="c-etype" wire:model="entry.escalation_type" class="sh-input">
                                @foreach ($escalationTypes as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                            </select>
                            <p class="mt-1 text-xs text-subtle">The business is alerted immediately. Urgent ones always reach them.</p>
                        </div>
                    @endif
                    @if ($isCallback)
                        <p class="sm:col-span-2 text-sm text-muted"><x-ui.icon name="callback" class="mr-1 inline size-4" />A call-back task is created for the business automatically, due within an hour of opening time.</p>
                    @endif
                    <div class="sm:col-span-2">
                        <label for="c-notes" class="sh-label">Notes</label>
                        <textarea id="c-notes" wire:model="entry.notes" rows="3" class="sh-input" placeholder="What the caller needs, in their words."></textarea>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="3. Book and follow up">
                <label class="flex items-center gap-2 text-sm font-medium text-ink">
                    <input type="checkbox" wire:model.live="entry.book" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Book an appointment
                </label>
                @if ($entry['book'])
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="c-service" class="sh-label">Service</label>
                            <select id="c-service" wire:model.live="entry.service_id" class="sh-input">
                                <option value="">General appointment</option>
                                @foreach ($bookable as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->duration_minutes }} min)</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="c-date" class="sh-label">Date</label>
                            <input id="c-date" type="date" wire:model.live="entry.date" min="{{ $localNow->toDateString() }}" class="sh-input">
                            @error('entry.date') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <span class="sh-label">Free times ({{ $timezone }})</span>
                            @if ($slots !== [])
                                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Free times">
                                    @foreach ($slots as $slot)
                                        <button type="button" role="radio" aria-checked="{{ $entry['time'] === $slot->format('H:i') ? 'true' : 'false' }}" wire:click="pickTime('{{ $slot->format('Y-m-d H:i') }}')"
                                            @class(['rounded-lg px-3 py-1.5 text-sm tabular-nums ring-1',
                                                'bg-brand-600 text-white ring-brand-500' => $entry['time'] === $slot->format('H:i'),
                                                'bg-surface-2 text-ink ring-line hover:ring-brand-400' => $entry['time'] !== $slot->format('H:i')])>{{ $slot->format('g:i A') }}</button>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-sm text-muted">No free times that day within the business's hours and rules. Try another day or offer a call-back.</p>
                            @endif
                            @error('entry.time') <p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p> @enderror
                            @if ($suggestions !== [])
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($suggestions as $s)
                                        <x-ui.button size="sm" variant="secondary" wire:click="pickTime('{{ $s }}')">{{ \Carbon\CarbonImmutable::parse($s)->format('D j M, g:i A') }}</x-ui.button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                <label class="mt-4 flex items-center gap-2 text-sm font-medium text-ink">
                    <input type="checkbox" wire:model.live="entry.follow_up" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Create a follow-up for the business
                </label>
                @if ($entry['follow_up'])
                    <div class="mt-3 grid gap-4 sm:grid-cols-[1fr_12rem]">
                        <div>
                            <label for="c-fu" class="sh-label">What should they do?</label>
                            <input id="c-fu" type="text" wire:model="entry.follow_up_title" class="sh-input" maxlength="250" placeholder="e.g. Send the repipe quote">
                            @error('entry.follow_up_title') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="c-fu-date" class="sh-label">By</label>
                            <input id="c-fu-date" type="date" wire:model="entry.follow_up_date" min="{{ $localNow->toDateString() }}" class="sh-input">
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="newCall" wire:confirm="Clear this call?">Clear</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save call</x-ui.button>
            </div>
        </form>

        {{-- Briefing (spec §21): everything about this business on one screen --}}
        <aside class="space-y-6 lg:col-span-2" aria-label="Business briefing">
            @if ($history)
                <x-ui.card title="{{ ($confirmed ?? $match['customer'] ?? null)?->fullName() }}'s history">
                    @if ($history['appointments']->isNotEmpty())
                        <p class="text-xs font-medium uppercase tracking-wide text-subtle">Upcoming</p>
                        @foreach ($history['appointments'] as $a)<p class="mt-1 text-sm text-ink">{{ $a->whenLabel() }} · {{ $a->title }}</p>@endforeach
                    @endif
                    @if ($history['tasks']->isNotEmpty())
                        <p class="mt-3 text-xs font-medium uppercase tracking-wide text-subtle">Waiting on the business</p>
                        @foreach ($history['tasks'] as $t)<p class="mt-1 text-sm text-ink">{{ $t->title }}</p>@endforeach
                    @endif
                    <p class="mt-3 text-xs font-medium uppercase tracking-wide text-subtle">Recent calls</p>
                    @forelse ($history['calls'] as $c)
                        <p class="mt-1 text-sm text-ink">{{ $c->created_at->setTimezone($timezone)->format('M j') }} · {{ \Illuminate\Support\Str::headline((string) $c->reason_for_call) }} <span class="text-muted">· {{ $c->statusLabel() }}</span></p>
                    @empty
                        <p class="mt-1 text-sm text-muted">First call.</p>
                    @endforelse
                </x-ui.card>
            @endif

            @if ($briefing !== [] || $profile?->emergency_available)
                <x-ui.card title="Rules for this business">
                    <ul class="space-y-2 text-sm text-ink" role="list">
                        @foreach ($briefing as $line)<li class="flex gap-2"><x-ui.icon name="shield" class="mt-0.5 size-4 shrink-0 text-brand-300" />{{ $line }}</li>@endforeach
                        @if ($profile?->emergency_available)
                            <li class="flex gap-2"><x-ui.icon name="alert" class="mt-0.5 size-4 shrink-0 text-red-300" />Takes emergencies{{ $profile->emergency_instructions ? ': '.$profile->emergency_instructions : '.' }}</li>
                        @endif
                    </ul>
                </x-ui.card>
            @endif

            <x-ui.card title="Knowledge">
                <label for="kb-find" class="sr-only">Search knowledge</label>
                <input id="kb-find" type="search" wire:model.live.debounce.250ms="knowledgeSearch" class="sh-input mb-3" placeholder="Search answers, policies, prices…" autocomplete="off">
                @forelse ($knowledge as $item)
                    <details class="group border-b border-line py-2 last:border-0" @if ($item->is_pinned || $knowledgeSearch !== '') open @endif>
                        <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-ink">
                            @if (in_array($item->type, [\App\Enums\KnowledgeType::EmergencyInstruction, \App\Enums\KnowledgeType::EscalationRule], true))<x-ui.icon name="alert" class="size-4 text-red-300" />@endif
                            <span class="flex-1">{{ $item->title }}</span>
                            @if ($item->visibility === \App\Enums\KnowledgeVisibility::Public)<x-ui.badge tone="success">OK to share</x-ui.badge>@endif
                        </summary>
                        <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $item->content }}</p>
                    </details>
                @empty
                    <p class="text-sm text-muted">{{ $knowledgeSearch !== '' ? 'Nothing matches.' : 'This business hasn\'t added knowledge yet.' }}</p>
                @endforelse
            </x-ui.card>

            <x-ui.card title="Services">
                @forelse ($services as $s)
                    <div class="border-b border-line py-2 last:border-0">
                        <p class="flex items-baseline justify-between gap-2 text-sm"><span class="font-medium text-ink">{{ $s->name }}</span><span class="text-muted">{{ $priceLabel($s) }}</span></p>
                        <p class="text-xs text-subtle">{{ $s->duration_minutes }} min{{ $s->is_bookable ? '' : ' · not bookable' }}</p>
                        @if ($s->agent_instructions)<p class="mt-1 text-xs text-amber-200">{{ $s->agent_instructions }}</p>@endif
                    </div>
                @empty
                    <p class="text-sm text-muted">No services listed.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card title="Hours">
                <dl class="space-y-1 text-sm">
                    @foreach ($weekly as $day => $ranges)
                        <div @class(['flex justify-between gap-4', 'font-semibold text-ink' => $day === $localNow->format('l'), 'text-muted' => $day !== $localNow->format('l')])>
                            <dt>{{ $day }}</dt><dd>{{ $ranges ? implode(', ', $ranges) : 'Closed' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="Today's appointments">
                @forelse ($todaysAppointments as $a)
                    <p class="text-sm text-ink"><span class="tabular-nums text-muted">{{ $a->localStart()->format('g:i A') }}</span> · {{ $a->title }}</p>
                @empty
                    <p class="text-sm text-muted">Nothing booked today.</p>
                @endforelse
            </x-ui.card>

            @if ($profile?->description || $profile?->service_area)
                <x-ui.card title="About">
                    @if ($profile->description)<p class="whitespace-pre-line text-sm text-ink">{{ $profile->description }}</p>@endif
                    @if ($profile->service_area)<p class="mt-2 text-sm text-muted">Service area: {{ $profile->service_area }}</p>@endif
                </x-ui.card>
            @endif
        </aside>
    </div>
</div>
