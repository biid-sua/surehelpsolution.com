<div>
    <x-ui.page-header title="Notification settings" description="Choose how you hear about what's happening. These settings are just for you, {{ \Illuminate\Support\Str::before(trim(auth()->user()->name).' ', ' ') }}." />

    <form wire:submit="save">
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
