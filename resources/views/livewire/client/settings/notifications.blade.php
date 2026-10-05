<div>
    <x-ui.page-header title="Notification settings" description="Choose how you hear about what's happening. These settings are just for you, {{ \Illuminate\Support\Str::before(trim(auth()->user()->name).' ', ' ') }}." />

    <form wire:submit="save">
        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            <x-ui.card title="Daily summary" description="One email each morning: yesterday's calls and bookings, today's appointments and what's waiting for you.">
                <label for="n-summary" class="sh-label">Send it at</label>
                <select id="n-summary" wire:model="summaryAt" class="sh-input">
                    <option value="off">Don't send me a daily summary</option>
                    @foreach ($summaryTimes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-subtle">Your time ({{ str_replace('_', ' ', $timezone) }}). Days with nothing to report send nothing.</p>
                @error('summaryAt') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </x-ui.card>
            <x-ui.card title="Quiet hours" description="Emails wait until morning. In-app notifications still arrive quietly, and urgent escalations always come through.">
                <label class="flex items-center gap-2 text-sm font-medium text-ink">
                    <input type="checkbox" wire:model.live="quietOn" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Hold emails overnight
                </label>
                @if ($quietOn)
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted">
                        <span>From</span>
                        <input type="time" wire:model="quietStart" class="sh-input w-32" aria-label="Quiet hours start">
                        <span>until</span>
                        <input type="time" wire:model="quietEnd" class="sh-input w-32" aria-label="Quiet hours end">
                    </div>
                    @error('quietEnd') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                @endif
            </x-ui.card>
        </div>

        <x-ui.card :padding="false">
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        @foreach ($channels as $key => $channel)
                            <th scope="col" class="text-center">
                                {{ $channel['label'] }}
                                @unless ($channel['enabled'])
                                    <span class="block text-[10px] font-medium normal-case tracking-normal text-subtle">Coming soon</span>
                                @endunless
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($events as $event)
                        <tr wire:key="pref-{{ $event->value }}">
                            <td>
                                <p class="font-medium text-ink">{{ $event->label() }}</p>
                                <p class="text-xs text-subtle">{{ $event->description() }}</p>
                            </td>
                            @foreach ($channels as $key => $channel)
                                <td class="text-center align-middle">
                                    @if ($channel['enabled'])
                                        <input type="checkbox" id="pref-{{ $event->value }}-{{ $key }}"
                                            wire:model="preferences.{{ $event->name }}.{{ $key }}"
                                            class="size-5 rounded border-line-strong bg-surface-2 text-brand-500 focus:ring-brand-400"
                                            aria-label="{{ $channel['label'] }} for {{ $event->label() }}">
                                    @else
                                        <input type="checkbox" disabled class="size-5 rounded border-line bg-surface-3 opacity-40"
                                            aria-label="{{ $channel['label'] }} for {{ $event->label() }} (coming soon)">
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-4">
                <p class="text-xs text-subtle">Emails go to {{ auth()->user()->email }}.</p>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save settings</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
