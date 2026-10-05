<div>
    <x-ui.page-header title="Business" description="When you're open decides how our agents handle each call.">
        <x-slot:actions>
            <x-ui.badge :tone="$status['open'] ? 'success' : 'neutral'" class="text-sm">{{ $status['label'] }}</x-ui.badge>
        </x-slot:actions>
    </x-ui.page-header>
    @include('livewire.client.business._tabs')

    @if (! $organization->timezone)
        <x-ui.alert class="mb-6" tone="warning" title="Set your timezone first">
            Hours are read in your business's timezone. <a href="{{ route('app.business.profile') }}" class="font-semibold underline underline-offset-2">Choose it on the Profile tab.</a>
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-6">
            <x-ui.card title="Weekly hours" description="Add a second shift for a lunch break. A day with no shifts is closed. A closing time earlier than the opening time runs past midnight.">
                <x-slot:actions>
                    @if ($canEdit)
                        <x-ui.button variant="ghost" size="sm" wire:click="copyMondayToWeekdays">Copy Monday to Tue–Fri</x-ui.button>
                    @endif
                </x-slot:actions>

                <div class="divide-y divide-line">
                    @foreach ($dayNames as $day => $name)
                        <div class="grid gap-3 py-3 sm:grid-cols-[8rem_1fr]" wire:key="day-{{ $day }}">
                            <p class="pt-2 text-sm font-medium text-ink">{{ $name }}</p>
                            <div class="space-y-2">
                                @forelse ($days[$day] as $i => $interval)
                                    <div class="flex flex-wrap items-center gap-2" wire:key="day-{{ $day }}-{{ $i }}">
                                        <label class="sr-only" for="o-{{ $day }}-{{ $i }}">{{ $name }} shift {{ $i + 1 }} opens</label>
                                        <input id="o-{{ $day }}-{{ $i }}" type="time" wire:model="days.{{ $day }}.{{ $i }}.opens" class="sh-input w-32">
                                        <span class="text-subtle">to</span>
                                        <label class="sr-only" for="c-{{ $day }}-{{ $i }}">{{ $name }} shift {{ $i + 1 }} closes</label>
                                        <input id="c-{{ $day }}-{{ $i }}" type="time" wire:model="days.{{ $day }}.{{ $i }}.closes" class="sh-input w-32">
                                        @if ($canEdit)
                                            <button type="button" wire:click="removeInterval({{ $day }}, {{ $i }})" class="rounded-lg p-2 text-subtle hover:bg-surface-2 hover:text-ink" aria-label="Remove {{ $name }} shift {{ $i + 1 }}">
                                                <x-ui.icon name="x" class="size-4" />
                                            </button>
                                        @endif
                                        @error("days.$day.$i") <p class="w-full text-sm text-danger">{{ $message }}</p> @enderror
                                    </div>
                                @empty
                                    <p class="pt-2 text-sm text-subtle">Closed</p>
                                @endforelse
                                @if ($canEdit)
                                    <button type="button" wire:click="addInterval({{ $day }})" class="text-sm font-medium text-brand-300 hover:text-brand-400">
                                        + {{ empty($days[$day]) ? 'Open this day' : 'Add another shift' }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <div class="grid gap-6 lg:grid-cols-2">
                <x-ui.card title="Emergencies" description="Can callers reach you for emergencies outside your hours?">
                    <label class="flex items-center gap-3 text-sm text-ink">
                        <input type="checkbox" wire:model.live="emergencyAvailable" class="size-5 rounded border-line-strong bg-surface-2 text-brand-500 focus:ring-brand-400">
                        Yes, I take emergency calls after hours
                    </label>
                    @if ($emergencyAvailable)
                        <label for="emergency" class="sh-label mt-4">What should agents do in an emergency?</label>
                        <textarea id="emergency" wire:model="emergencyInstructions" rows="3" class="sh-input"
                            placeholder="e.g. Burst pipes and no heat in winter: transfer to my cell. Everything else: book the next morning."></textarea>
                    @endif
                </x-ui.card>

                <x-ui.card title="Vacation mode" description="Away for a holiday or renovation? Our receptionists tell callers, take messages and don't book anything on those days.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="closed-from" class="sh-label">Away from</label>
                            <input id="closed-from" type="date" wire:model="closedFrom" class="sh-input">
                            @error('closedFrom') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="closed-until" class="sh-label">Back after (last day away)</label>
                            <input id="closed-until" type="date" wire:model="closedUntil" class="sh-input">
                            @error('closedUntil') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="closure-msg" class="sh-label">What should we tell callers and do while you're away?</label>
                            <input id="closure-msg" type="text" wire:model="closureMessage" class="sh-input" maxlength="255" placeholder="We're on vacation until Monday the 12th. Take a message; emergencies go to Mike on (512) 555-0199.">
                            <p class="mt-1 text-xs text-subtle">Leave both dates empty to turn vacation mode off. Emergency handling above still applies.</p>
                        </div>
                    </div>
                </x-ui.card>
            </div>
        </fieldset>

        @if ($canEdit)
            <div class="flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save hours</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </div>
        @endif
    </form>

    {{-- Holidays & special hours --}}
    <x-ui.card class="mt-6" title="Holidays & special hours" description="Closed for a day, or open different hours on a specific date." :padding="false">
        @if ($holidays->isEmpty())
            <x-ui.empty-state icon="calendar" title="No upcoming holidays" description="Add dates you're closed or open different hours, like Thanksgiving or Christmas Eve." />
        @else
            <ul class="divide-y divide-line">
                @foreach ($holidays as $holiday)
                    <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="holiday-{{ $holiday->id }}">
                        <div>
                            <p class="text-sm font-medium text-ink">{{ $holiday->name }}</p>
                            <p class="text-xs text-subtle">{{ $holiday->date->format('l, M j, Y') }} ·
                                {{ $holiday->is_closed ? 'Closed' : \Illuminate\Support\Carbon::parse($holiday->opens_at)->format('g:i A').' – '.\Illuminate\Support\Carbon::parse($holiday->closes_at)->format('g:i A') }}</p>
                        </div>
                        @if ($canEdit)
                            <x-ui.button variant="ghost" size="sm" wire:click="removeHoliday({{ $holiday->id }})">Remove</x-ui.button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canEdit)
            <form wire:submit="addHoliday" class="grid gap-3 border-t border-line px-5 py-4 sm:grid-cols-[10rem_1fr_auto] sm:items-end">
                <div>
                    <label for="h-date" class="sh-label">Date</label>
                    <input id="h-date" type="date" wire:model="newHoliday.date" class="sh-input">
                    @error('newHoliday.date') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-name" class="sh-label">Name</label>
                    <input id="h-name" type="text" wire:model="newHoliday.name" class="sh-input" placeholder="e.g. Thanksgiving">
                    @error('newHoliday.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <x-ui.button type="submit" variant="secondary">Add date</x-ui.button>
                <div class="sm:col-span-3 flex flex-wrap items-center gap-4 text-sm">
                    <label class="flex items-center gap-2 text-ink">
                        <input type="radio" wire:model.live="newHoliday.is_closed" value="1" class="border-line-strong bg-surface-2 text-brand-500"> Closed all day
                    </label>
                    <label class="flex items-center gap-2 text-ink">
                        <input type="radio" wire:model.live="newHoliday.is_closed" value="0" class="border-line-strong bg-surface-2 text-brand-500"> Special hours
                    </label>
                    @if (! filter_var($newHoliday['is_closed'], FILTER_VALIDATE_BOOLEAN))
                        <input type="time" wire:model="newHoliday.opens" class="sh-input w-32" aria-label="Opens">
                        <span class="text-subtle">to</span>
                        <input type="time" wire:model="newHoliday.closes" class="sh-input w-32" aria-label="Closes">
                        @error('newHoliday.opens') <p class="w-full text-sm text-danger">{{ $message }}</p> @enderror
                        @error('newHoliday.closes') <p class="w-full text-sm text-danger">{{ $message }}</p> @enderror
                    @endif
                </div>
            </form>
        @endif
    </x-ui.card>
</div>
